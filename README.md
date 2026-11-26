# FeatherPanel module for Blesta

> **Requires FeatherPanel v1.5.6 or newer.** Older panels lack the external-ID lookups for VDS and WebSpaces.

Sell FeatherPanel products from Blesta 5. Blesta sets each service up, suspends it, unsuspends it, upgrades it and removes it on the panel. Your clients get a status card and a one-click login.

| Product type | What gets created on FeatherPanel |
|---|---|
| Game server | A server on a node (or any node in a location that has a free port), with a realm, a spell, limits and variables |
| VDS | A virtual machine on a VDS node from a template, with memory, CPU sockets and cores, disk, storage, bridge and backups |
| Web hosting | A WebSpace on a web node, with a webplate, an optional hosting package, limits and a domain |

## Requirements

- Blesta 5.x with PHP 8.1+ and cURL (tested on Blesta 5.13.12 with PHP 8.3).
- FeatherPanel **v1.5.6** or newer (needs the external-ID lookups for VDS and WebSpaces, and VDS resizes saved on the instance).
- A FeatherPanel admin API key. Create it under Admin, API keys. Keys have full admin access.

## Install

1. Copy the `components` folder from this repository into your Blesta root. That creates `components/modules/featherpanel/`.
2. In Blesta go to Settings, Company, Modules, Available, and install **FeatherPanel**.
3. Add a panel. Fill in:
   - **Panel URL**: the address clients open, for example `https://panel.example.com`.
   - **Admin API key**: the key from Requirements.
   - **Verify SSL**: whether to check the panel's SSL certificate.
   - **External ID prefix**: defaults to `blesta-`. Use a different prefix for each Blesta installation that shares one panel.
   - **Wait for a new VDS**: how many seconds activation waits for the clone. The default is 60.

   Blesta checks the URL and the key when you save.
4. Create a package with module **FeatherPanel** and pick the product type. The fields reload for that type, with nodes, realms, spells, templates, webplates and hosting packages loaded from the panel.

### Package fields

| Type | Fields |
|---|---|
| Game | location, node (or automatic), realm, spell, memory, swap, disk (MiB), CPU %, IO, CPU pinning, databases, backups, extra ports, image and startup (empty uses the spell default), variables (`KEY=value` per line), out of memory killer, skip install script |
| VDS | VDS node, template, memory (MiB), CPU sockets, cores, disk (GB), storage, bridge, Proxmox node, backups, monthly traffic, login user, start with host |
| Web hosting | web node, webplate, hosting package, disk (MiB), CPU cores, memory, traffic, databases, mailboxes, SSL |

To let clients pick limits at order time, add configurable options whose **name** matches a field key, for example `memory`, `disk`, `cpu`, `cores` or `database_limit`. The option value then replaces the package value.

### Order fields

| Type | Order field |
|---|---|
| Game | Server name |
| VDS | Hostname and an optional root password (empty generates one) |
| Web hosting | Domain |

## What each Blesta action does

| Blesta action | Panel |
|---|---|
| Activate (add service) | Finds the client's panel account by email, or creates one; the username and password are kept in Blesta. Then creates the server, VDS or WebSpace. |
| Suspend / Unsuspend | Suspends or unsuspends it, using Blesta's suspension reason. |
| Cancel | Deletes it. Cancelling something that is already gone counts as done. The panel account is kept for the client's other services. |
| Change package | Applies the new limits: VDS disks grow but never shrink; WebSpaces move to the new hosting package. Moving between product types is refused. |
| Edit (admin) | Renames the server, VDS hostname or WebSpace domain. Admins can also relink a service to another panel ID, UUID or external ID. |
| Client tab "Panel" | Shows the status, and an "Open in FeatherPanel" button that creates a one-time login link to that server, VDS or WebSpace. The button is hidden while the service is suspended. |

### Running activation twice is safe

Every panel resource gets the external ID `<prefix>svc-<Blesta service id>`. Before creating anything the module looks that ID up and adopts what it finds, so a retried activation never creates a second server.

- **WebSpaces** also use a UUID derived from the external ID.
- **VDS** clones can take longer than the wait. In that case the service keeps the creation job ID and finds the VDS on the next action.

If the panel is unreachable or answers with an error, the action fails, so nothing is created twice or wrongly marked as deleted.

Passwords, tokens, mail bodies and the API key never go into the Blesta module log.

## Testing

See [TESTING.md](TESTING.md).

## License

MIT, see [LICENSE](LICENSE).
