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

## Run

```bash
sudo cp /opt/php-wifi/examples/provision/provision.service /etc/systemd/system/provision.service
sudo systemctl daemon-reload
sudo systemctl enable --now provision
```

This starts a `femus-setup` hotspot and serves the provisioning page on it
at `http://10.42.0.1:8080/` (NetworkManager's default hotspot address).

## Use it from a phone

1. Join the `femus-setup` Wi-Fi network (password from `PROVISION_PASSWORD`).
2. Open `http://10.42.0.1:8080/` in the phone's browser.
3. Pick the home network from the list, enter its password, and tap Connect.

The screenshot below is from the provisioning page running on the
maintainer's Raspberry Pi.

![Phone screenshot](screenshot.jpg)
