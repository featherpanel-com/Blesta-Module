# How this module was tested

The module was tested in a real **Blesta 5.13.12** install (PHP 8.3, ionCube, trial licence) against a
real FeatherPanel backend (`develop` at `5294739e`, which includes the billing external-ID API), its
async runner, MariaDB and Redis. Only the node daemons (Wings, Proxmox, Quilld) were local stand-ins.

## Blesta UI and order flow

Everything was done through Blesta's own pages, the way an admin and a client would:

1. Install the module, add a panel (Blesta validates the URL and key), and create one package group
   with two packages per product type (game server, VDS, web hosting).
2. Order, using two paths:
   - **Admin order**: Clients → Add Service → pending service with an invoice;
   - **Client storefront**: an Order plugin form; the client puts a game server, a VDS and a WebSpace
     in one cart and checks out.
3. Record the payment, then run Blesta's cron. The *provision paid pending services* task activates
   each service on the panel.
4. For each product type:
   - the service exists on the panel with external ID `blesta-svc-<service id>`;
   - the admin and client **Panel** tabs show the status;
   - the client tab's **Open in FeatherPanel** link signs the client into their own panel account,
     and the link works only once;
   - Suspend (with Blesta's reason) suspends it on the panel, and the client tab hides the login
     button while suspended; Unsuspend restores it;
   - a package change applies the new limits on the panel, and a change to another product type is
     refused;
   - an admin edit renames the server, VDS hostname or WebSpace domain on the panel;
   - Cancel removes it from the panel.

Results (26.11.2026): **13/13 checks for each product type** on the admin-order and storefront paths.
The last run was against FeatherPanel `develop` `5294739e`. The client-chosen VDS root password never
appeared in Blesta's module log, and it is stored encrypted, on VDS services only.

## Module lifecycle and unit tests

- A lifecycle run inside the Blesta runtime: activation twice (no duplicates), suspend, unsuspend,
  upgrade, rename, cancel and log masking for all three types. 65/65 checks passed.
- 20 unit tests against a scripted panel API.
