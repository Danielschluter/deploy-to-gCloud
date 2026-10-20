#!/usr/bin/env bash
# Creates a Compute Engine VM (first run) and deploys this app to it with Docker Compose.
# Re-run it to push code changes. Run from Cloud Shell or any machine with gcloud logged in:
#
#   PROJECT_ID=my-project ./deploy/gcp-deploy.sh
#
# Optional overrides: ZONE, VM_NAME, MACHINE_TYPE.
set -euo pipefail

PROJECT_ID="${PROJECT_ID:-$(gcloud config get-value project 2>/dev/null || true)}"
ZONE="${ZONE:-us-central1-a}"
REGION="${ZONE%-*}"
VM_NAME="${VM_NAME:-php-postgres-app}"
MACHINE_TYPE="${MACHINE_TYPE:-e2-small}"
ADDRESS_NAME="$VM_NAME-ip"
APP_DIR=/opt/app

if [ -z "$PROJECT_ID" ]; then
  echo "No project set. Run with PROJECT_ID=<id> or 'gcloud config set project <id>'." >&2
  exit 1
fi

cd "$(dirname "$0")/.."

gc() { gcloud --project "$PROJECT_ID" --quiet "$@"; }
vm_ssh() {
  gc compute ssh "$VM_NAME" --zone "$ZONE" --strict-host-key-checking=no \
    --ssh-flag=-oConnectTimeout=10 --command "$1"
}

echo "==> Enabling the Compute Engine API in $PROJECT_ID"
gc services enable compute.googleapis.com

if ! gc compute firewall-rules describe default-allow-http >/dev/null 2>&1; then
  echo "==> Creating firewall rule default-allow-http (tcp:80 to VMs tagged http-server)"
  gc compute firewall-rules create default-allow-http --network default \
    --allow tcp:80 --target-tags http-server --source-ranges 0.0.0.0/0
fi

# Prints the reserved IP on stdout; progress goes to stderr.
reserve_address() {
  if ! gc compute addresses describe "$ADDRESS_NAME" --region "$REGION" >/dev/null 2>&1; then
    echo "==> Reserving static IP $ADDRESS_NAME" >&2
    gc compute addresses create "$ADDRESS_NAME" --region "$REGION" >&2
  fi
  gc compute addresses describe "$ADDRESS_NAME" --region "$REGION" --format='value(address)'
}

if ! gc compute instances describe "$VM_NAME" --zone "$ZONE" >/dev/null 2>&1; then
  IP="$(reserve_address)"
  echo "==> Creating VM $VM_NAME ($MACHINE_TYPE, $ZONE)"
  gc compute instances create "$VM_NAME" --zone "$ZONE" \
    --machine-type "$MACHINE_TYPE" \
    --image-family debian-12 --image-project debian-cloud \
    --boot-disk-size 20GB \
    --tags http-server \
    --address "$IP"
else
  # The VM may have been created elsewhere (console, connector): make sure the
  # firewall rule applies to it and that it has a static external IP.
  gc compute instances add-tags "$VM_NAME" --zone "$ZONE" --tags http-server
  IP="$(gc compute instances describe "$VM_NAME" --zone "$ZONE" \
    --format='value(networkInterfaces[0].accessConfigs[0].natIP)')"
  if [ -z "$IP" ]; then
    IP="$(reserve_address)"
    echo "==> Attaching external IP $IP to $VM_NAME"
    gc compute instances add-access-config "$VM_NAME" --zone "$ZONE" --address "$IP"
  elif [ -z "$(gc compute addresses list --filter="address=$IP" --format='value(name)')" ]; then
    echo "==> Promoting $IP to static IP $ADDRESS_NAME"
    gc compute addresses create "$ADDRESS_NAME" --region "$REGION" --addresses "$IP"
  fi
fi

# gcloud would otherwise prompt for a passphrase when creating its SSH key.
if [ ! -f "$HOME/.ssh/google_compute_engine" ]; then
  mkdir -p "$HOME/.ssh"
  ssh-keygen -t rsa -b 3072 -N '' -q -f "$HOME/.ssh/google_compute_engine"
fi

echo "==> Waiting for SSH on $VM_NAME"
for attempt in $(seq 1 30); do
  if vm_ssh true >/dev/null 2>&1; then
    break
  fi
  if [ "$attempt" -eq 30 ]; then
    echo "Could not SSH to $VM_NAME. Try: gcloud compute ssh $VM_NAME --zone $ZONE" >&2
    exit 1
  fi
  sleep 10
done

echo "==> Uploading app to $VM_NAME:$APP_DIR"
COPYFILE_DISABLE=1 tar -czf - --exclude=.env --exclude=nginx/.htpasswd \
    docker-compose.prod.yml deploy nginx php src \
  | vm_ssh "sudo mkdir -p $APP_DIR && sudo rm -rf $APP_DIR/src && sudo tar -xzf - -C $APP_DIR"

echo "==> Running setup on the VM"
vm_ssh "sudo bash $APP_DIR/deploy/vm-setup.sh"

echo
echo "Deployed: http://$IP/"
echo "Pages:    http://$IP/csv-import.php  http://$IP/view-tables.php"
