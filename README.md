# osTicket Ticket Webhook Plugin

A plugin for [osTicket](https://osticket.com/) that sends an HTTP POST webhook with JSON ticket details whenever a new ticket is created. Supports department filtering, HTTP Basic Auth, multi-instance configuration, and reverse-proxy environments.

## Features

- **Webhook on ticket creation** -- sends a JSON POST request with full ticket details
- **Department filtering** -- trigger only for selected departments, or all
- **HTTP Basic Auth** -- optional username/password authentication
- **Multi-instance** -- create multiple instances with different URLs, credentials, and department filters
- **Reverse-proxy aware** -- DNS resolve override for environments where the webhook domain points to the same server
- **Custom headers** -- add an extra HTTP header per instance (e.g. API key)
- **Debug logging** -- optional per-instance logging to `webhook.log`

## Requirements

- osTicket **1.18+**
- PHP **7.4+**
- PHP cURL extension (`php-curl`)

## Installation

1. Download or clone this repository into your osTicket plugins directory:

   ```bash
   cd /path/to/osticket/include/plugins
   git clone https://github.com/<your-org>/osticket-ticket-webhook.git ticket-webhook
   ```

   Or copy the files manually so the structure looks like:

   ```
   include/plugins/ticket-webhook/
   ├── plugin.php
   └── include/
       └── class.ticket-webhook.php
   ```

2. In osTicket, go to **Admin Panel > Manage > Plugins > Add New Plugin**.

3. Select **Ticket Webhook** from the list and click **Install**.

4. The plugin is now installed but needs at least one **instance** to be configured.

## Configuration

### Creating an instance

1. Go to **Admin Panel > Manage > Plugins > Ticket Webhook**.
2. Click **Add New Instance**.
3. Give it a name (e.g. "Production Webhook") and fill in the configuration.
4. Check **Active** to enable the instance.

### Configuration fields

#### Webhook Settings

| Field | Description |
|---|---|
| **Enable Webhook** | Master on/off toggle for this instance. |
| **Webhook URL** | The full URL that receives the POST request (e.g. `https://example.com/api/webhook`). |

#### Authentication (Basic Auth)

| Field | Description |
|---|---|
| **Username** | HTTP Basic Auth username. Leave blank for no authentication. |
| **Password** | HTTP Basic Auth password. Stored encrypted by osTicket. |

#### Department Filter

| Field | Description |
|---|---|
| **Departments** | Select one or more departments. Only tickets in these departments trigger the webhook. **Leave empty to trigger for ALL departments.** |

#### Advanced Settings

| Field | Description |
|---|---|
| **Verify SSL Certificate** | Verify the endpoint's SSL certificate. Disable for self-signed certs. Default: enabled. |
| **Resolve Host Override (IP)** | Enter the real backend IP if the webhook domain is reverse-proxied by the same server osTicket runs on. This prevents DNS loopback issues. Enter only a bare IP address (e.g. `10.0.1.50`), not `IP:port`. Leave empty for normal DNS resolution. |
| **Custom Header** | An optional extra HTTP header sent with every request (e.g. `X-Api-Key: abc123`). |
| **Enable Debug Logging** | Write detailed request/response information to `webhook.log` inside the plugin directory. Useful for troubleshooting; disable in production. |

## Webhook payload

The plugin sends a `POST` request with `Content-Type: application/json`. Example payload:

```json
{
  "event": "ticket.created",
  "ticket": {
    "id": 1234,
    "number": "ABC-567",
    "subject": "Cannot access VPN",
    "status": "Open",
    "priority": "High",
    "sla": "Default SLA",
    "source": "Web",
    "due_date": "2026-04-15 17:00:00",
    "created": "2026-04-10 09:30:00"
  },
  "department": {
    "id": 1,
    "name": "IT Support"
  },
  "requester": {
    "name": "Jane Doe",
    "email": "jane.doe@example.com"
  },
  "help_topic": "General Inquiry",
  "assigned_to": {
    "staff": {
      "id": 5,
      "name": "John Admin",
      "email": "john.admin@example.com"
    }
  }
}
```

The `assigned_to` field is `null` when the ticket is not yet assigned. It may contain `staff`, `team`, or both.

## Reverse-proxy environments

If osTicket and the webhook target (e.g. an n8n or Zapier instance) are behind the **same reverse proxy**, cURL requests from PHP loop back to the local web server instead of reaching the real backend. This typically causes SSL/SNI errors like:

```
AH02032: Hostname webhook.example.com provided via SNI and hostname
osticket.example.com provided via HTTP have no compatible SSL setup
```

**Two solutions:**

1. **Direct URL** (recommended): set the Webhook URL to the backend's internal address, e.g. `http://10.0.1.50:5678/webhook/endpoint`. This bypasses the proxy entirely.

2. **Resolve Host Override**: keep the public URL but set the Resolve Host Override field to the real backend IP. cURL will connect directly to that IP while keeping the original `Host` header and SNI intact.

## Troubleshooting

1. **Enable Debug Logging** in the instance's Advanced Settings.
2. Create a test ticket in a monitored department.
3. Check `include/plugins/ticket-webhook/webhook.log` for detailed output.
4. Errors are also written to osTicket's **Admin Panel > Dashboard > System Logs**.

**Common issues:**

| Symptom | Cause | Fix |
|---|---|---|
| No log entries at all | Plugin not active, or no enabled instances | Check plugin status and instance **Active** checkbox |
| `Call to undefined function curl_init()` | `php-curl` not installed | `sudo apt install php8.1-curl && sudo systemctl restart php8.1-fpm` |
| SSL handshake errors in Apache log | Webhook domain resolves to same server | Use a direct internal URL or the Resolve Host Override field |
| `Couldn't parse CURLOPT_RESOLVE entry` | Resolve field contains port (e.g. `10.0.1.50:5678`) | Enter only the bare IP address, no port |
| Webhook fires but returns HTTP 401 | Auth credentials wrong | Verify Basic Auth username and password |

## Multi-instance example

You can create multiple instances to route different departments to different endpoints:

| Instance | Departments | Webhook URL |
|---|---|---|
| "IT Alerts" | IT Support | `https://hooks.slack.com/services/xxx` |
| "Sales CRM" | Sales, Pre-Sales | `https://crm.example.com/api/ticket` |
| "All to n8n" | *(empty = all)* | `http://10.0.1.50:5678/webhook/osticket` |

## License

This project is licensed under the [MIT License](LICENSE).
