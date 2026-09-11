# Headless Wi-Fi provisioning on a Raspberry Pi

A small demo that turns a headless Raspberry Pi into a Wi-Fi setup wizard:
it opens a temporary hotspot, a phone joins it, and a plain PHP page lets
the phone pick a network and a password — no SSH, no keyboard, no monitor.

## Install

`examples/` is `export-ignore`d from the package tarball, so a `composer
require` install will not have it. Clone the repository instead:

```bash
sudo git clone https://github.com/sanchescom/php-wifi.git /opt/php-wifi
cd /opt/php-wifi
composer install --no-dev
```

## Configure

Create the environment file the systemd unit reads, and lock it down since
it holds the hotspot password:

```bash
sudo tee /etc/php-wifi-provision.env >/dev/null <<'EOF'
PROVISION_SSID=femus-setup
PROVISION_PASSWORD=change-me
EOF
sudo chmod 600 /etc/php-wifi-provision.env
```

`nmcli` (used by `bin/wifi`) refuses to scan or connect for a process
running as `www-data` unless polkit grants it. Install the polkit rule from
the [Linux — Privileges (polkit)](../../README.md#linux--privileges-polkit)
section of the project's main README.md before starting the service.

Optional environment variables, all with sane defaults:

| Variable            | Default                                  | Meaning                                                |
|---------------------|-------------------------------------------|--------------------------------------------------------|
| `PROVISION_CACHE`   | `/run/php-wifi-provision/networks.json`   | Where the pre-hotspot scan is cached as JSON.          |
| `PROVISION_DONE`    | `/run/php-wifi-provision/done`            | Marker file created once a connection succeeds.        |
| `PROVISION_TIMEOUT` | `900`                                      | Seconds `hotspot.sh` waits for `PROVISION_DONE` before giving up. |
| `RESTART_BACKOFF`   | `5`                                         | Seconds to wait between hotspot restart attempts (see below). |
| `MAX_RESTARTS`      | `5`                                         | Consecutive failed restart attempts before `hotspot.sh` gives up. |

`RuntimeDirectory=php-wifi-provision` in `provision.service` makes systemd
create and clean up `/run/php-wifi-provision` automatically, so these two
files never need to be provisioned by hand.

## Run

```bash
sudo cp /opt/php-wifi/examples/provision/provision.service /etc/systemd/system/provision.service
sudo systemctl daemon-reload
sudo systemctl enable --now provision
```

This starts a `femus-setup` hotspot and serves the provisioning page on it
at `http://10.42.0.1:8080/` (NetworkManager's default hotspot address).

## How it ends itself

A single Wi-Fi radio can either scan or run an access point, never both, so
`hotspot.sh` scans first (`wifi list --unique --json`) and caches the result
to `PROVISION_CACHE` before starting the hotspot — that is why the page can
still show the neighbouring networks even though, once the hotspot is up, a
live scan would only see the hotspot itself. `index.php` reads that cache on
every `GET` and labels the list "Scanned before the hotspot started."; a
missing or unreadable cache falls back to a live scan.

Once a connection succeeds, `index.php` writes an empty marker file at
`PROVISION_DONE`. `hotspot.sh` polls for that marker once a second, and as
soon as it appears (or `PROVISION_TIMEOUT` seconds pass, or the web server
dies) it stops the built-in PHP server, runs `wifi hotspot stop`, and exits.
The unit does not restart itself afterwards — the provisioning run is meant
to happen once per boot.

## Recovering from a mistyped passphrase

A single radio also means a *failed* join drops the access point: to attempt
the join at all, NetworkManager has to tear the hotspot down first, and on a
wrong passphrase it leaves the radio disconnected instead of putting the
hotspot back. Without help, that would strand the person mid-setup until
`PROVISION_TIMEOUT` expires. `hotspot.sh` checks `wifi hotspot status` on
every iteration of its wait loop and, if it comes back `inactive` while
`PROVISION_DONE` is still absent, restarts the hotspot so they can try again.
Restarts are throttled by `RESTART_BACKOFF` and capped at `MAX_RESTARTS`
consecutive failures, so a genuinely broken radio still ends the run instead
of spinning.

## Use it from a phone

1. Join the `femus-setup` Wi-Fi network (password from `PROVISION_PASSWORD`).
2. Open `http://10.42.0.1:8080/` in the phone's browser.
3. Pick the home network from the list, enter its password, and tap Connect.

The screenshot below is from the provisioning page running on the
maintainer's Raspberry Pi.

![Phone screenshot](screenshot.jpg)
