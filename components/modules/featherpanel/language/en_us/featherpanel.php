<?php
// Basics
$lang['Featherpanel.name'] = 'FeatherPanel';
$lang['Featherpanel.description'] = 'Sells game servers, VDS (virtual machines) and web hosting (WebSpaces) on FeatherPanel, with automatic setup, suspension and removal.';
$lang['Featherpanel.module_row'] = 'Panel';
$lang['Featherpanel.module_row_plural'] = 'Panels';
$lang['Featherpanel.module_group'] = 'Panel Group';
$lang['Featherpanel.order_options.first'] = 'First panel in the group';
$lang['Featherpanel.order_options.least'] = 'Panel with the fewest services';

// Manage
$lang['Featherpanel.add_module_row'] = 'Add Panel';
$lang['Featherpanel.add_module_group'] = 'Add Panel Group';
$lang['Featherpanel.manage.module_rows_title'] = 'Panels';
$lang['Featherpanel.manage.module_groups_title'] = 'Panel Groups';
$lang['Featherpanel.manage.module_rows_heading.name'] = 'Name';
$lang['Featherpanel.manage.module_rows_heading.panel_url'] = 'Panel URL';
$lang['Featherpanel.manage.module_rows_heading.options'] = 'Options';
$lang['Featherpanel.manage.module_groups_heading.name'] = 'Group Name';
$lang['Featherpanel.manage.module_groups_heading.servers'] = 'Panels';
$lang['Featherpanel.manage.module_groups_heading.options'] = 'Options';
$lang['Featherpanel.manage.module_rows.edit'] = 'Edit';
$lang['Featherpanel.manage.module_groups.edit'] = 'Edit';
$lang['Featherpanel.manage.module_rows.delete'] = 'Delete';
$lang['Featherpanel.manage.module_groups.delete'] = 'Delete';
$lang['Featherpanel.manage.module_rows.confirm_delete'] = 'Are you sure you want to delete this panel?';
$lang['Featherpanel.manage.module_groups.confirm_delete'] = 'Are you sure you want to delete this group?';
$lang['Featherpanel.manage.module_rows_no_results'] = 'There are no panels yet.';
$lang['Featherpanel.manage.module_groups_no_results'] = 'There are no panel groups yet.';

// Add / edit row
$lang['Featherpanel.add_row.box_title'] = 'Add FeatherPanel';
$lang['Featherpanel.edit_row.box_title'] = 'Edit FeatherPanel';
$lang['Featherpanel.row.basic_title'] = 'Connection';
$lang['Featherpanel.row.add_btn'] = 'Add Panel';
$lang['Featherpanel.row.edit_btn'] = 'Update Panel';
$lang['Featherpanel.row_meta.server_name'] = 'Name';
$lang['Featherpanel.row_meta.panel_url'] = 'Panel URL';
$lang['Featherpanel.row_meta.api_key'] = 'Admin API key';
$lang['Featherpanel.row_meta.verify_ssl'] = 'Verify the panel SSL certificate';
$lang['Featherpanel.row_meta.external_id_prefix'] = 'External ID prefix';
$lang['Featherpanel.row_meta.vds_wait_seconds'] = 'Wait for a new VDS (seconds)';
$lang['Featherpanel.!tooltip.panel_url'] = 'The address clients open, for example https://panel.example.com.';
$lang['Featherpanel.!tooltip.api_key'] = 'Create it in FeatherPanel under Admin, API keys. It needs full admin access.';
$lang['Featherpanel.!tooltip.external_id_prefix'] = 'Added in front of the Blesta service id on every panel resource, so the same service is never created twice. Use a different prefix for each Blesta installation that shares the panel.';
$lang['Featherpanel.!tooltip.vds_wait_seconds'] = 'How long activation waits for a new VDS to finish cloning. If it takes longer the service stays linked to the creation job and picks the VDS up on the next action.';

// Product types
$lang['Featherpanel.product_type.game'] = 'Game server';
$lang['Featherpanel.product_type.vds'] = 'VDS (virtual machine)';
$lang['Featherpanel.product_type.webspace'] = 'Web hosting (WebSpace)';

// Package fields
$lang['Featherpanel.package.product_type'] = 'Product type';
$lang['Featherpanel.package.any'] = 'Any location';
$lang['Featherpanel.package.auto_node'] = 'Pick a node with a free port';
$lang['Featherpanel.package.none'] = 'None';
$lang['Featherpanel.package.location_id'] = 'Location';
$lang['Featherpanel.package.node_id'] = 'Node';
$lang['Featherpanel.package.realm_id'] = 'Realm';
$lang['Featherpanel.package.spell_id'] = 'Spell';
$lang['Featherpanel.package.memory'] = 'Memory (MiB)';
$lang['Featherpanel.package.swap'] = 'Swap (MiB)';
$lang['Featherpanel.package.disk'] = 'Disk';
$lang['Featherpanel.package.cpu'] = 'CPU limit (%)';
$lang['Featherpanel.package.io'] = 'Block IO weight (10-1000)';
$lang['Featherpanel.package.threads'] = 'CPU pinning';
$lang['Featherpanel.package.database_limit'] = 'Databases';
$lang['Featherpanel.package.backup_limit'] = 'Backups';
$lang['Featherpanel.package.allocation_limit'] = 'Extra ports';
$lang['Featherpanel.package.image'] = 'Docker image';
$lang['Featherpanel.package.startup'] = 'Startup command';
$lang['Featherpanel.package.environment'] = 'Variables';
$lang['Featherpanel.package.oom_killer'] = 'Enable the out of memory killer';
$lang['Featherpanel.package.skip_scripts'] = 'Skip the spell install script';
$lang['Featherpanel.package.vm_node_id'] = 'VDS node';
$lang['Featherpanel.package.template_id'] = 'Template';
$lang['Featherpanel.package.cpus'] = 'CPU sockets';
$lang['Featherpanel.package.cores'] = 'Cores per socket';
$lang['Featherpanel.package.storage'] = 'Storage';
$lang['Featherpanel.package.bridge'] = 'Network bridge';
$lang['Featherpanel.package.pve_node'] = 'Proxmox node name';
$lang['Featherpanel.package.bandwidth_limit_gb'] = 'Monthly traffic (GB)';
$lang['Featherpanel.package.ci_user'] = 'Login user';
$lang['Featherpanel.package.on_boot'] = 'Start with the host';
$lang['Featherpanel.package.web_node_id'] = 'Web node';
$lang['Featherpanel.package.webplate_id'] = 'Webplate';
$lang['Featherpanel.package.hosting_package_id'] = 'Hosting package';
$lang['Featherpanel.package.cpu_limit'] = 'CPU limit (cores)';
$lang['Featherpanel.package.memory_limit'] = 'Memory (MiB)';
$lang['Featherpanel.package.mailbox_limit'] = 'Mailboxes';
$lang['Featherpanel.package.ssl'] = 'Issue an SSL certificate';
$lang['Featherpanel.package.tooltip.product_type'] = 'Changing the type reloads the fields below.';
$lang['Featherpanel.package.tooltip.node_id'] = 'Leave on automatic to use any node in the location that has a free port.';
$lang['Featherpanel.package.tooltip.disk'] = 'Game servers and WebSpaces use MiB, VDS use GB.';
$lang['Featherpanel.package.tooltip.environment'] = 'One KEY=value per line. Overrides the spell defaults.';
$lang['Featherpanel.package.tooltip.image'] = 'Leave empty to use the spell default.';
$lang['Featherpanel.package.tooltip.startup'] = 'Leave empty to use the spell default.';
$lang['Featherpanel.package.tooltip.hosting_package_id'] = 'When set, the hosting package limits are used and the limit fields below are only a fallback.';
$lang['Featherpanel.package.tooltip.storage'] = 'Leave empty to use the template storage.';
$lang['Featherpanel.package.tooltip.bandwidth_limit_gb'] = 'Leave empty for the node default, 0 for unlimited.';

// Service fields
$lang['Featherpanel.service.server_name'] = 'Server name';
$lang['Featherpanel.service.hostname'] = 'Hostname';
$lang['Featherpanel.service.domain'] = 'Domain';
$lang['Featherpanel.service.password'] = 'Root password';
$lang['Featherpanel.service.tooltip.server_name'] = 'Shown in the panel. Leave empty to use the package name.';
$lang['Featherpanel.service.tooltip.hostname'] = 'For example vps1.example.com.';
$lang['Featherpanel.service.tooltip.domain'] = 'The domain the site answers on, for example example.com.';
$lang['Featherpanel.service.tooltip.password'] = 'At least 8 characters. Leave empty to get a random one.';
$lang['Featherpanel.service.featherpanel_id'] = 'Panel ID';
$lang['Featherpanel.service.featherpanel_uuid'] = 'Panel UUID';
$lang['Featherpanel.service.featherpanel_external_id'] = 'External ID';

// Service info / tabs
$lang['Featherpanel.tab.manage'] = 'Panel';
$lang['Featherpanel.info.type'] = 'Type';
$lang['Featherpanel.info.name'] = 'Name';
$lang['Featherpanel.info.address'] = 'Address';
$lang['Featherpanel.info.domain'] = 'Domain';
$lang['Featherpanel.info.panel_user'] = 'Panel username';
$lang['Featherpanel.info.panel_password'] = 'Panel password';
$lang['Featherpanel.info.vds_login'] = 'VDS login';
$lang['Featherpanel.info.panel'] = 'Panel';
$lang['Featherpanel.info.reference'] = 'Panel reference';
$lang['Featherpanel.info.pending'] = 'Being created';
$lang['Featherpanel.tab.status'] = 'Status';
$lang['Featherpanel.tab.state.unknown'] = 'Unknown';
$lang['Featherpanel.tab.not_found'] = 'This service was not found on the panel.';
$lang['Featherpanel.tab.creating'] = 'This VDS is still being created. Check back in a few minutes.';
$lang['Featherpanel.tab.suspended'] = 'This service is suspended.';
$lang['Featherpanel.tab.login_btn'] = 'Open in FeatherPanel';
$lang['Featherpanel.tab.login_ready'] = 'Your login link is ready. It works once and expires in a few minutes.';
$lang['Featherpanel.tab.login_open'] = 'Open the panel';

// Errors
$lang['Featherpanel.!error.server_name.empty'] = 'Enter a name for this panel.';
$lang['Featherpanel.!error.panel_url.format'] = 'Enter the panel URL, starting with http:// or https://.';
$lang['Featherpanel.!error.external_id_prefix.format'] = 'The prefix can use letters, numbers and . _ : - (up to 40 characters).';
$lang['Featherpanel.!error.vds_wait_seconds.range'] = 'Enter a number of seconds between 0 and 600.';
$lang['Featherpanel.!error.api_key.empty'] = 'Enter the admin API key.';
$lang['Featherpanel.!error.api_key.valid'] = 'The panel could not be reached with this URL and API key.';
$lang['Featherpanel.!error.no_row'] = 'No FeatherPanel is set up for this package yet.';
$lang['Featherpanel.!error.lists'] = 'Could not load the options from the panel: %1$s';
$lang['Featherpanel.!error.product_type.valid'] = 'Choose a product type.';
$lang['Featherpanel.!error.package.required'] = '%1$s is required.';
$lang['Featherpanel.!error.package.number'] = '%1$s must be a number of 0 or more.';
$lang['Featherpanel.!error.package.type_change'] = 'A service cannot move to a package of a different product type.';
$lang['Featherpanel.!error.domain.format'] = 'Enter a valid domain, for example example.com.';
$lang['Featherpanel.!error.hostname.format'] = 'Enter a valid hostname, for example vps1.example.com.';
$lang['Featherpanel.!error.password.length'] = 'The password must be at least 8 characters.';
$lang['Featherpanel.!error.server_name.length'] = 'The server name can be at most 191 characters.';
$lang['Featherpanel.!error.login.suspended'] = 'The panel login is not available while the service is suspended.';
