<?php
/* Copyright 2015, Guilherme Jardim
 * Copyright 2016-2022, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$plugin = "unassigned.devices";
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';

/* add translations */
$_SERVER['REQUEST_URI'] = 'unassigneddevices';
require_once "$docroot/webGui/include/Translations.php";

require_once("plugins/{$plugin}/include/lib.php");
require_once("webGui/include/Helpers.php");

if (isset($_POST['display'])) {
	$display = $_POST['display'];
}
if (isset($_POST['var'])) {
	$var = $_POST['var'];
}

function netmasks($netmask, $rev = false)
{
	$netmasks = [	"255.255.255.252"	=> "30",
					"255.255.255.248"	=> "29",
					"255.255.255.240"	=> "28",
					"255.255.255.224"	=> "27",
					"255.255.255.192"	=> "26",
					"255.255.255.128"	=> "25",
					"255.255.255.0"		=> "24",
					"255.255.254.0"		=> "23",
					"255.255.252.0"		=> "22",
					"255.255.248.0"		=> "21",
					"255.255.240.0" 	=> "20",
					"255.255.224.0" 	=> "19",
					"255.255.192.0" 	=> "18",
					"255.255.128.0" 	=> "17",
					"255.255.0.0"		=> "16",
				];

	return $rev ? array_flip($netmasks)[$netmask] : $netmasks[$netmask];
}

/* Get the version of Unraid we are running. */
$version = @parse_ini_file("/etc/unraid-version");

/* Get the diskio scale based on the current setting. */
function my_diskio($data) {
	return my_scale($data, $unit, 1)." $unit/s";
}

/* Get the used and free space for a partition and render for html. */
function render_used_and_free($partition) {
	global $display;

	/* Only show used and free when disk is mounted. */
	if ($partition['target']) {
		$free_pct = $partition['size'] ? round(100*$partition['avail']/$partition['size']) : 0;
		$used_pct = 100-$free_pct;

		/* Display of disk usage depends on global display setting. */
		if ($display['text'] % 10 == 0) {
			$out = "<td>".my_scale($partition['used'], $unit)." $unit</td>";
		} else {
			$out = "<td><div class='usage-disk'><span style='margin:0;width:$used_pct%' class='".usage_color($display,$used_pct,false)."'></span><span>".my_scale($partition['used'], $unit)." $unit</span></div></td>";
		}

		/* Display of disk usage depends on global display setting. */
		if ($display['text'] < 10 ? $display['text'] % 10 == 0 : $display['text'] % 10 != 0) {
			$out .= "<td>".my_scale($partition['avail'], $unit)." $unit</td>";
		} else {
			$out .= "<td><div class='usage-disk'><span style='margin:0;width:$free_pct%' class='".usage_color($display,$free_pct,true)."'></span><span>".my_scale($partition['avail'], $unit)." $unit</span></div></td>";
		}
	} else {
		$out = "<td></td><td></td>";
	}

	return $out;
}

/* Get the used and free space for a disk and render for html. */
function render_used_and_free_disk($disk, $mounted) {
	global $display;

	if ($mounted) {
		$size	= 0;
		$avail	= 0;
		$used	= 0;
		foreach ($disk['partitions'] as $partition) {
			$size	+= $partition['size'];
			$avail	+= $partition['avail'];
			$used 	+= $partition['used'];
		}
		$free_pct = $size ? round(100*$avail/$size) : 0;
		$used_pct = 100-$free_pct;
		if ($display['text'] % 10 == 0) {
			$out = "<td>".my_scale($used, $unit)." $unit</td>";
		} else {
			$out = "<td><div class='usage-disk'><span style='margin:0;width:$used_pct%' class='".usage_color($display,$used_pct,false)."'></span><span>".my_scale($used, $unit)." $unit</span></div></td>";
		}
		if ($display['text'] < 10 ? $display['text'] % 10 == 0 : $display['text'] % 10 != 0) {
			$out .= "<td>".my_scale($avail, $unit)." $unit</td>";
		} else {
			$out .= "<td><div class='usage-disk'><span style='margin:0;width:$free_pct%' class='".usage_color($display,$free_pct,true)."'></span><span>".my_scale($avail, $unit)." $unit</span></div></td>";
		}
	} else {
		$out = "<td></td><td></td>";
	}

	return $out;
}

/* Get the partition information and render for html. */
function render_partition($disk, $partition, $disk_line = false) {
	global $paths, $plugin, $Preclear;

	$out = array();
	if (isset($partition['device'])) {
		$mounted		= $partition['mounted'];
		$cmd			= $partition['command'];
		$device			= $partition['fstype'] == "crypto_LUKS" ? $partition['luks'] : $partition['device'];
		$is_mounting	= array_values(preg_grep("@/mounting_".basename($device)."@i", listDir(dirname($paths['mounting']))))[0];
		$is_mounting	= (time() - filemtime($is_mounting) < 300) ? true : false;
		$is_unmounting	= array_values(preg_grep("@/unmounting_".basename($device)."@i", listDir(dirname($paths['unmounting']))))[0];
		$is_unmounting	= (time() - filemtime($is_unmounting) < 300) ? true : false;
		$disabled		= $is_mounting || $is_unmounting || is_script_running($cmd) || ! $partition['fstype'] || $disk['array_disk'];

		/* Set up icons for file system check/scrub and script execution. */
		$fstype = ($partition['fstype'] == "crypto_LUKS") ? luks_fs_type($partition['device']) : $partition['fstype'];
		if ( (! $disabled && ! $mounted && $fstype != "apfs" && $fstype != "btrfs") || (! $disabled && $mounted && $fstype == "btrfs")) {
			$file_system_check = $fstype != "btrfs" ? _('File System Check') : _('File System Scrub');
			$fscheck = "<a class='exec info' onclick='openWindow_fsck(\"/plugins/{$plugin}/include/fsck.php?device={$partition['device']}&fs={$partition['fstype']}&luks={$partition['luks']}&serial={$partition['serial']}&check_type=ro&type="._('Done')."\",\"Check filesystem\",600,900);'><i class='fa fa-check partition-hdd'></i><span>".$file_system_check."</span></a>";
		} else {
			$fscheck = "<i class='fa fa-check partition-hdd'></i></a>";
		}
		if ($mounted && is_file($cmd)) {
			if ((! $disabled && ! is_script_running($cmd)) && (! is_script_running($partition['user_command'], true))) {
				$fscheck .= "<a class='exec info' onclick='openWindow_fsck(\"/plugins/{$plugin}/include/script.php?device={$device}&type="._('Done')."\",\"Execute Script\",600,900);'><i class='fa fa-flash partition-script'></i><span>"._("Execute Script as udev simulating a device being installed")."</span></a>";
			} else {
				$fscheck .= "<i class='fa fa-flash partition-script'></i>";
			}
		} else if ($mounted) {
			$fscheck .= "<i class='fa fa-flash partition-script'></i>";
		}
		$fscheck .= $partition['part'];

		/* Add remove partition icon if destructive mode is enabled. */
		$preclearing	= $Preclear ? $Preclear->isRunning(basename(MiscUD::base_device($partition['device']))) : false;
		$is_preclearing = shell_exec("/usr/bin/ps -ef | /bin/grep 'preclear' | /bin/grep ".escapeshellarg(MiscUD::base_device($partition['device']))." | /bin/grep -v 'grep'") != "" ? true : false;
		$preclearing	= $preclearing || $is_preclearing;
		$rm_partition	= ((get_config("Config", "destructive_mode") == "enabled") && (! $is_mounting) && (! $disk['partitions'][0]['pass_through']) && (! $disk['partitions'][0]['disable_mount']) && (! $disk['array_disk']) && (! $preclearing) && ($fstype)) ? "<a device='{$partition['device']}' class='exec info' style='color:#CC0000;font-weight:bold;' onclick='rm_partition(this,\"{$partition['serial']}\",\"{$disk['device']}\",\"{$partition['part']}\");'><i class='fa fa-remove hdd'></i><span>"._("Remove Partition")."</span></a>" : "";
		$mpoint			= "<span>{$fscheck}";
		$mount_point	= basename($partition['mountpoint']);

		/* Add change mount point or browse disk share icon if disk is mounted. */
		if ($mounted) {
			$mpoint .= "<i class='fa fa-external-link partition-hdd'></i><a title='"._("Browse Disk Share")."' href='/Main/Browse?dir={$partition['mountpoint']}'>{$mount_point}</a></span>";
		} else {
			$mount_point	= basename($partition['mountpoint']);
			$disk_label		= $partition['disk_label'];
			if ((! $disk['array_disk']) && (! $preclearing)) {
				$mpoint			.= "<i class='fa fa-pencil partition-hdd'></i><a title='"._("Change Disk Mount Point")."' class='exec' onclick='chg_mountpoint(\"{$partition['serial']}\",\"{$partition['part']}\",\"{$device}\",\"{$partition['fstype']}\",\"{$mount_point}\",\"{$disk_label}\");'>{$mount_point}</a>";
			} else {
				$mpoint			.= "<i class='fa fa-pencil partition-hdd'></i>{$mount_point}";
			}
			$mpoint			.= "{$rm_partition}</span>";
		}
		$mbutton = make_mount_button($partition);

		/* Show disk partitions if partitions enabled. */
		(! $disk['show_partitions']) || $disk['partitions'][0]['pass_through'] ? $style = "style='display:none;'" : $style = "";
		$out[] = "<tr class='toggle-parts toggle-".basename($disk['device'])."' name='toggle-".basename($disk['device'])."' $style>";
		$out[] = "<td></td>";
		$out[] = "<td>{$mpoint}</td>";
		$out[] = ((count($disk['partitions']) > 1) && ($mounted)) ? "<td class='mount'>{$mbutton}</td>" : "<td></td>";
		$fstype = $partition['fstype'];
		if ($disk_line) {
			foreach ($disk['partitions'] as $part) {
				if ($part['fstype']) {
					$fstype = $part['fstype'];
					break;
				}
			}
		}

		/* Disk read and write totals or rate. */
		if ($disk_line) {
			if (! isset($_COOKIE['diskio'])) {
				$out[] = "<td>".my_number($disk['reads'])."</td>";
				$out[] = "<td>".my_number($disk['writes'])."</td>";
			} else {
				$out[] = "<td>".my_diskio($disk['read_rate'])."</td>";
				$out[] = "<td>".my_diskio($disk['write_rate'])."</td>";
			}
		} else {
			$out[] = "<td></td><td></td><td></td>";
		}

		/* Set up the device settings and script settings tooltip. */
		$title = _("Edit Device Settings and Script");
		if ($disk_line) {
			$title .= "<br />"._("Passed Through").": ";
			$title .= ($partition['pass_through'] == 'yes') ? "Yes" : "No";
			$title .= "<br />"._("Disable Mount Button").": ";
			$title .= ($partition['disable_mount'] == 'yes') ? "Yes" : "No";
			$title .= "<br />"._("Read Only").": ";
			$title .= ($partition['read_only'] == 'yes') ? "Yes" : "No";
			$title .= "<br />"._("Automount").": ";
			$title .= ($partition['automount'] == 'yes') ? "Yes" : "No";
		}
		$title .= "<br />"._("Share").": ";
		$title .= ($partition['shared'] == 'yes') ? "Yes" : "No";
		if ($disk_line) {
			$title .= "<br />"._("Show Partitions").": ";
			$title .= ($disk['show_partitions'] == 'yes') ? "Yes" : "No";
		}

		$device		= MiscUD::base_device(basename($device)) ;
		$serial		= $partition['serial'];
		$id_bus		= $disk['id_bus'];
		if (! $disk['array_disk']) {
			$out[]		= "<td><a class='info' href='/Main/EditDeviceSettings?s=".$serial."&b=".$device."&f=".$fstype."&l=".basename($partition['mountpoint'])."&p=".$partition['part']."&m=".json_encode($partition)."&t=".$disk_line."&u=".$id_bus."'><i class='fa fa-gears'></i><span style='text-align:left'>$title</span></a></td>";
		} else {
			$out[]		= "<td><i class='fa fa-gears' disabled></i></td>";
		}
		if ($disk_line) {
			$mounted_disk = false;
			foreach ($disk['partitions'] as $part) {
				if ($part['mounted']) {
					$mounted_disk = true;
					break;
				}
			}
		}

		/* Show disk and partition usage. */
		$out[] = "<td>".($fstype == "crypto_LUKS" ? luks_fs_type($partition['device']) : $fstype)."</td>";
		if ($disk_line) {
			$out[] = render_used_and_free_disk($disk, $mounted_disk);
		} else {
			$out[] = "<td>".my_scale($partition['size'], $unit)." $unit</td>";
			$out[] = render_used_and_free($partition);
		}

		/* Add device log icon. */
		if ((! $disk_line) || (! $disk['show_partitions'])) {
			$out[] = "<td><a title='"._("View Device Script Log")."' href='/Main/ScriptLog?s=".$partition['serial']."&p=".$partition['part']."'><i class='fa fa-align-left".( $partition['command'] ? "":" grey-orb" )."'></i></a></td>";
		} else {
			$out[] = "<td></td>";
		}
		$out[] = "</tr>";
	}

	return $out;
}

/* Format the mount button based on status of the device. */
function make_mount_button($device) {
	global $paths, $Preclear;

	$button = "<span><button device='{$device['device']}' class='mount' context='%s' role='%s' %s><i class='%s'></i>%s</button></span>";

	if (isset($device['partitions'])) {
		$mounted		= in_array(true, array_map(function($ar){return $ar['mounted'];}, $device['partitions']), true);
		$disable		= count(array_filter($device['partitions'], function($p){ if (! empty($p['fstype'])) return true;})) ? "" : "disabled";
		$format			= (! count($device['partitions'])) ? true : false;
		$context		= "disk";
		$pool_disk		= $device['partitions'][0]['pool'];
		$pass_through	= $device['partitions'][0]['pass_through'];
		$disable_mount	= $device['partitions'][0]['disable_mount'];
		$not_unmounted	= $device['partitions'][0]['not_unmounted'];
	} else {
		$mounted		= $device['mounted'];
		$disable		= (! empty($device['fstype']) && $device['fstype'] != "crypto_LUKS") ? "" : "disabled";
		$format			= (empty($device['fstype'])) ? true : false;
		$context		= "partition";
		$pool_disk		= false;
		$pass_through	= $device['pass_through'];
		$disable_mount	= $device['disable_mount'];
		$not_unmounted	= false;
	}

	$is_mounting	= array_values(preg_grep("@/mounting_".basename($device['device'])."@i", listDir(dirname($paths['mounting']))))[0];
	$is_mounting	= (time() - filemtime($is_mounting) < 300) ? true : false;
	$is_unmounting	= array_values(preg_grep("@/unmounting_".basename($device['device'])."@i", listDir(dirname($paths['unmounting']))))[0];
	$is_unmounting	= (time() - filemtime($is_unmounting) < 300) ? true : false;
	$is_formatting	= array_values(preg_grep("@/formatting_".basename($device['device'])."@i", listDir(dirname($paths['formatting']))))[0];
	$is_formatting	= (time() - filemtime($is_formatting) < 300) ? true : false;

	$preclearing	= $Preclear ? $Preclear->isRunning(basename($device['device'])) : false;
	$is_preclearing = shell_exec("/usr/bin/ps -ef | /bin/grep 'preclear' | /bin/grep ".escapeshellarg($device['device'])." | /bin/grep -v 'grep'") != "" ? true : false;
	$preclearing	= $preclearing || $is_preclearing;

	$disable		= ( ($pass_through) || ($disable_mount) || ($preclearing) || ($not_unmounted) ) ? "disabled" : $disable;
	$class			= ( ($pass_through) || ($disable_mount) || ($not_unmounted) ) ? "fa fa-ban" : "";

	if ($pool_disk) {
		$button = sprintf($button, $context, 'mount', 'disabled', '', _('Pool'));
	} else if (($device['size'] == 0) && (! $is_unmounting)) {
		$button = sprintf($button, $context, 'mount', 'disabled', '', _('Mount'));
	} else if ($device['array_disk']) {
		$button = sprintf($button, $context, 'mount', 'disabled', 'fa fa-ban', _('Array'));
	} else if (($format) || ($preclearing)) {
		if ($preclearing) {
			$button = sprintf($button, $context, 'mount', 'disabled', '', " "._('Preclear'));
		} else {
			$disable = $preclearing ? "disabled" : "";
			$button = sprintf($button, $context, 'format', $disable, '', _('Format'));
		}
	} else if ($is_mounting) {
		$button = sprintf($button, $context, 'mount', 'disabled', 'fa fa-spinner fa-spin', ' '._('Mounting'));
	} else if ($is_unmounting) {
		$button = sprintf($button, $context, 'umount', 'disabled', 'fa fa-spinner fa-spin', ' '._('Unmounting'));
	} else if ($is_formatting) {
		$button = sprintf($button, $context, 'format', 'disabled', 'fa fa-spinner fa-spin', ' '._('Formatting'));
	} else if ($mounted) {
		if (! isset($device['partitions'])) {
			$cmd = $device['command'];
			$user_cmd = $device['user_command'];
			$script_running = ((is_script_running($cmd)) || (is_script_running($user_cmd, true)));;
		} else {
			foreach ($device['partitions'] as $part) {
				$cmd = $part['command'];
				$user_cmd		= $part['user_command'];
				$script_running	= ((is_script_running($cmd)) || (is_script_running($user_cmd, true)));;
				if ($script_running) {
					break;
				}
			}
		}
		if ($script_running) {
			$button = sprintf($button, $context, 'running', 'disabled', 'fa fa-spinner fa-spin', ' '._('Running'));
		} else if ($not_unmounted) {
			$button = sprintf($button, $context, 'umount', $disable, $class, _('Reboot'));
		} else {
			$button = sprintf($button, $context, 'umount', $disable, $class, _('Unmount'));
		}
	} else {
		if ($pass_through) {
			$button = sprintf($button, $context, 'mount', $disable, '', _('Passed'));	
		} else {
			$button = sprintf($button, $context, 'mount', $disable, $class, _('Mount'));	
		}
	}

	return $button;
}

switch ($_POST['action']) {
	case 'get_content':
		/* Update the UD webpage content. */

		/* Check for a recent hot plug event. */
		if (file_exists($paths['hotplug_event'])) {
			@unlink($paths['hotplug_event']);

			unassigned_log("Processing Hotplug event...", $UDEV_DEBUG);

			/* Tell Unraid to update list of unassigned devices in devs.ini. */
			exec("/usr/local/sbin/emcmd 'cmdHotplug=apply'");

			/* Get all updated unassigned disks and update devX designations for newly found unassigned devices. */
			$all_disks = get_all_disks_info();
			foreach ($all_disks as $disk) {
				$unassigned		= get_config($disk['serial'], "unassigned_dev");
				if ((! $unassigned) && ($disk['device'] != $disk['ud_dev'])) {
					set_config($disk['serial'], "unassigned_dev", $disk['ud_dev']);					
				} else if (($unassigned) && ((strpos($unassigned, "dev") !== false || strpos($unassigned, "sd") !== false) && ($unassigned != $disk['ud_dev']))) {
					set_config($disk['serial'], "unassigned_dev", $disk['ud_dev']);
				}
			}

			/* Update the preclear diskinfo. */
			if (file_exists("/etc/rc.d/rc.diskinfo")) {
				exec("/etc/rc.d/rc.diskinfo force &");
			}
		} else {
			$all_disks = get_all_disks_info();
		}

		/* Create empty array of share names for duplicate share checking. */
		$share_names	= array();
		$disk_uuid		= array();

		/* Create array of disk names. */
		$disk_names	= array();

		/* Disk devices. */
		$o_disks	= "";

		/* Get updated disks info in case devices have been hot plugged. */
		if ( count($all_disks) ) {
			foreach ($all_disks as $disk) {
				/* See if any partitions are mounted. */
				$mounted		= in_array(true, array_map(function($ar){return is_mounted($ar['mountpoint']);}, $disk['partitions']), true);

				/* See if any partitions have a file system. */
				$file_system	= in_array(true, array_map(function($ar){return ! empty($ar['fstype']);}, $disk['partitions']), true);

				$disk_device	= basename($disk['device']);
				$disk_dev		= $disk['ud_dev'];
				$disk_name		= $disk['unassigned_dev'] ? $disk['unassigned_dev'] : $disk['ud_dev'];
				$p				= (count($disk['partitions']) > 0) ? render_partition($disk, $disk['partitions'][0], true) : false;
				$preclearing	= $Preclear ? $Preclear->isRunning($disk_device) : false;
				$temp			= my_temp($disk['temperature']);

				/* Create the mount button. */
				$mbutton		= make_mount_button($disk);

				/* Set up the preclear link for preclearing a disk. */
				$preclear_link = (($disk['size'] !== 0) && (! $file_system) && (! $mounted) && ($Preclear) && (! $preclearing)) ? "&nbsp;&nbsp;".$Preclear->Link($disk_device, "icon") : "";

				/* Add the clear disk icon. */
				$is_mounting	= array_values(preg_grep("@/mounting_".basename($disk['device'])."@i", listDir(dirname($paths['mounting']))))[0];
				$is_mounting	= (time() - filemtime($is_mounting) < 300) ? true : false;
				$clear_disk		= ((get_config("Config", "destructive_mode") == "enabled") && ($p) && (! $mounted) && (! $disk['partitions'][0]['pool']) && (! $disk['partitions'][0]['disable_mount']) && (! $is_mounting) && (! $disk['partitions'][0]['pass_through']) && (! $disk['array_disk']) && (! $preclearing)) ? "<a device='{$partition['device']}' class='exec info' style='color:#CC0000;font-weight:bold;' onclick='clr_disk(this,\"{$partition['serial']}\",\"{$disk['device']}\");'><i class='fa fa-remove hdd'></i><span>"._("Clear Disk")."</span></a>" : "";

				$disk_icon = $disk['ssd'] ? "icon-nvme" : "fa fa-hdd-o";
				if (version_compare($version['version'],"6.9.9", ">")) {
					/* Disk log in 6.10. */
					$hdd_serial = "<a class='info' href=\"#\" onclick=\"openTerminal('disklog', '{$disk_device}')\"><i class='".$disk_icon." icon'></i><span>"._("Disk Log Information")."</span></a>";
				} else {
					/* Disk log in 6.9. */
					$hdd_serial = "<a class='info' href=\"#\" onclick=\"openBox('/webGui/scripts/disk_log&amp;arg1={$disk_device}','Disk Log Information',600,900,false);return false\"><i class='".$disk_icon." icon'></i><span>"._("Disk Log Information")."</span></a>";
				}
				if ($p) {
					$add_toggle = true;
					if (! $disk['show_partitions']) {
						$hdd_serial .="<span title ='"._("Click to view/hide partitions and mount points")."'class='exec toggle-hdd' hdd='".$disk_device."'><i class='fa fa-plus-square fa-append'></i></span>";
					} else {
						$hdd_serial .="<span><i class='fa fa-minus-square fa-append grey-orb'></i></span>";
					}
				} else {
					$add_toggle = false;
					$hdd_serial .= "<span class='toggle-hdd' hdd='".$disk_device."'></span>";
				}

				$device = strpos($disk_dev, "dev") === false ? "" : " ({$disk_device})";
				$hdd_serial .= $disk['serial'].$device.$preclear_link.$clear_disk."<span id='preclear_".$disk['serial_short']."' style='display:block;'></span>";

				$o_disks .= "<tr class='toggle-disk'>";
				if (strpos($disk_name, "dev") === false) {
					if (! $disk['array_disk']) {
						$disk_display = $disk_name;
					} else {
						$disk_display = $disk['ud_dev'];
					}
				} else {
					$disk_display = substr($disk_name, 0, 3)." ".substr($disk_name, 3);
					$disk_display = my_disk($disk_display);
				}

				$o_disks .= "<td>";
				if (strpos($disk_dev, "dev") === false) {
					$str = "New?name";
					$o_disks .= "<i class='fa fa-circle ".($disk['running'] ? "green-orb" : "grey-orb" )."'></i>";
				} else {
					$str = "Device?name";
					if (! $preclearing ) {
						if (! $disk['ssd']) {
							if (! is_disk_spin($disk['ud_dev'], $disk['running'])) {
								if ($disk['running']) {
									$o_disks .= "<a style='cursor:pointer' class='exec info' onclick='spin_down_disk(\"{$disk_dev}\")'><i id='disk_orb-{$disk_dev}' class='fa fa-circle green-orb'></i><span>"._("Click to spin down device")."</span></a>";
								} else {
									$o_disks .= "<a style='cursor:pointer' class='exec info' onclick='spin_up_disk(\"{$disk_dev}\")'><i id='disk_orb-{$disk_dev}' class='fa fa-circle grey-orb'></i><span>"._("Click to spin up device")."</span></a>";
								}
							} else {
								if ($disk['running']) {
									$o_disks .= "<i class='fa fa-refresh fa-spin green-orb'></i>";
								} else {
									$o_disks .= "<i class='fa fa-refresh fa-spin grey-orb'></i>";
								}
							}
						} else {
							$o_disks .= "<a class='info'><i class='fa fa-circle green-orb'></i><span>"._("SSD cannot be spun down")."</span></a>";
						}
					} else {
						$o_disks .= "<i class='fa fa-circle ".($disk['running'] ? "green-orb" : "grey-orb" )."'></i>";
					}
				}
				$fs_lock = ($disk['partitions'][0]['fstype'] == "crypto_LUKS") ? "<i class='fa fa-lock orb'></i>" : "";
				$o_disks .= "<a href='/Main/".$str."=".$disk_dev."'><span>".$fs_lock.$disk_display."</span></a>";
				$o_disks .= "</td>";

				/* Device serial number. */
				$o_disks .= "<td>{$hdd_serial}</td>";

				/* Mount button. */
				$o_disks .= "<td class='mount'>{$mbutton}</td>";

				/* Disk temperature. */
				$o_disks .= "<td>{$temp}</td>";

				if (! $p) {
					$rw = get_disk_reads_writes($disk['ud_dev'], $disk['device']);
					if (! isset($_COOKIE['diskio'])) {
						$reads		= my_number($rw[0]);
						$writes		= my_number($rw[1]);
					} else {
						$reads		= my_diskio($rw[2]);
						$writes		= my_diskio($rw[3]);
					}
				}

				/* Reads. */
				$o_disks .= ($p)?$p[4]:"<td>".$reads."</td>";

				/* Writes. */
				$o_disks .= ($p)?$p[5]:"<td>".$writes."</td>";

				/* Settings. */
				$o_disks .= ($p)?$p[6]:"<td></td>";

				/* File system. */
				$o_disks .= ($p)?$p[7]:"<td></td>";

				/* Disk size. */
				$o_disks .= "<td>".my_scale($disk['size'],$unit)." {$unit}</td>";

				/* Disk used and free space. */
				$o_disks .= ($p)?$p[8]:"<td></td><td></td>";

				/* Log button. */
				$o_disks .= ($p)?$p[9]:"<td></td>";
				$o_disks .= "</tr>";

				if ($add_toggle)
				{
					$o_disks .= "<tr>";
					foreach ($disk['partitions'] as $partition) {
						foreach (render_partition($disk, $partition) as $l)
						{
							$o_disks .= $l;
						}
					}
					$o_disks .= "</tr>";
				}

				/* Add to share names and disk names. */
				for ($i = 0; $i < count($disk['partitions']); $i++) {
					if (($disk['unassigned_dev']) && (! in_array($disk['unassigned_dev'], $disk_names))) {
						$disk_names[$disk_device] =  $disk['unassigned_dev'];
					}
					if ($disk['partitions'][$i]['fstype']) {
						$dev	= basename(($disk['partition'][$i]['fstype'] == "crypto_LUKS") ? $disk['luks'] : $disk['device']);
						if (MiscUD::is_device_nvme($dev)) {
							$dev .= "p";
						}

						/* Check if this disk uuid has already been entered in the share_names array. */
						$uuid		 	= $disk['partitions'][$i]['uuid'];
						$mountpoint		= basename($disk['partitions'][$i]['mountpoint']);
						$dev			.= $disk['partitions'][$i]['part'];
						if (! in_array($uuid, $disk_uuid)) {
							/* Disk is not a pooled device. */
							$share_names[$dev]				= $mountpoint;
							$disk_uuid[$dev]				= $uuid;
						} else {
							/* Disk is a pooled device. */
							$share_names					= array_flip($share_names);
							if (isset($share_names[$mountpoint])) {
								$share_names[$mountpoint]	.= "-";
							}
							$share_names[$mountpoint]		.= $dev;
							$share_names					= array_flip($share_names);
						}
					}
				}
			}
		} else {
			$o_disks .= "<tr><td colspan='12' style='text-align:center;'>"._('No Unassigned Disks available').".</td></tr>";
		}

		/* SAMBA Mounts. */
		$o_remotes = "";
		$ds1 = -microtime(true);
		$samba_mounts = get_samba_mounts();
		if (count($samba_mounts)) {
			foreach ($samba_mounts as $mount)
			{
				$is_alive = $mount['is_alive'];
				$mounted = $mount['mounted'];
				$o_remotes .= "<tr>";
				$protocol = $mount['protocol'] == "NFS" ? "nfs" : "smb";
				$o_remotes .= sprintf( "<td><a class='info'><i class='fa fa-circle orb %s'></i><span>"._("Remote Share is")." %s</span></a>%s</td>", ( $is_alive ? "green-orb" : "grey-orb" ), ( $is_alive ? _("online") : _("offline") ), $protocol);
				$o_remotes .= "<td>{$mount['name']}";
				$mount_point = basename($mount['mountpoint']);
				if ($mounted) {
					$o_remotes .= "<td><i class='fa fa-external-link mount-share'></i><a title='"._("Browse Remote SMB")."/"._("NFS Share")."' href='/Main/Browse?dir={$mount['mountpoint']}'>{$mount_point}</a></td>";
				} else {
					$o_remotes .= "<td>
						<i class='fa fa-pencil mount-share'></i>
						<a title='"._("Change Remote SMB")."/"._("NFS Mount Point")."' class='exec' onclick='chg_samba_mountpoint(\"{$mount['name']}\",\"{$mount_point}\");'>{$mount_point}</a>
						</td>";
				}

				$disabled	= (($mount['fstype'] == "root") && ($var['shareDisk'] == "yes" || $var['mdState'] != "STARTED")) ? "disabled" : ($is_alive ? "enabled" : "disabled");
				$disabled	= $mount['disable_mount'] == 'yes' ? "disabled" : $disabled;
				if ($mount['mounted'] && (is_script_running($mount['command']) || is_script_running($mount['user_command'], true))) {
					$o_remotes .= "<td><button class='mount' disabled> <i class='fa fa-spinner fa-spin'></i>"." "._("Running")."</button></td>";
				} else {
					$class	= $mount['disable_mount'] == "yes" ? "fa fa-ban" : "";
					/* Remove special characters. */
					$mount_device	= safe_name(basename($mount['device'])."_".$mount['fstype']);
					$is_mounting	= array_values(preg_grep("@/mounting_".$mount_device."@i", listDir(dirname($paths['mounting']))))[0];
					$is_mounting	= (time() - filemtime($is_mounting) < 300) ? true : false;
					$is_unmounting	= array_values(preg_grep("@/unmounting_".$mount_device."@i", listDir(dirname($paths['unmounting']))))[0];
					$is_unmounting	= (time() - filemtime($is_unmounting) < 300) ? true : false;
					if ($is_mounting) {
						$o_remotes .= "<td><button class='mount' disabled><i class='fa fa-spinner fa-spin'></i> "._('Mounting')."</button></td>";
					} else if ($is_unmounting) {
						$o_remotes .= "<td><button class='mount' disabled><i class='fa fa-spinner fa-spin'></i> "._('Unmounting')."</button></td>";
					} else {
						$o_remotes .= "<td>".($mounted ? "<button class='mount' device='{$mount['device']}' onclick=\"disk_op(this, 'umount','{$mount['device']}');\" {$disabled}><i class='$class'></i>"._('Unmount')."</button>" : "<button class='mount' device='{$mount['device']}' onclick=\"disk_op(this, 'mount','{$mount['device']}');\" {$disabled}><i class='$class'></i>"._('Mount')."</button>")."</td>";
					}
				}
				$o_remotes .= $mounted ? "<td><i class='fa fa-remove hdd'></i></td>" : "<td><a class='exec info' style='color:#CC0000;font-weight:bold;' onclick='remove_samba_config(\"{$mount['name']}\");'><i class='fa fa-remove hdd'></i><span>"._("Remove Remote SMB")."/"._("NFS Share")."</span></a></td>";

				$title = _("Edit Remote SMB")."/".("NFS Settings and Script");
				$title .= "<br />"._("Disable Mount Button").": ";
				$title .= ($mount['disable_mount'] == 'yes') ? "Yes" : "No";
				$title .= "<br />"._("Automount").": ";
				$title .= ($mount['automount'] == 'yes') ? "Yes" : "No";
				$title .= "<br />"._("Share").": ";
				$title .= ($mount['smb_share'] == 'yes') ? "Yes" : "No";

				$o_remotes .= "<td><a class='info' href='/Main/EditDeviceSettings?d=".$mount['device']."&l=".$mount_point."&m=".json_encode($mount)."'><i class='fa fa-gears'></i><span style='text-align:left'>$title</span></a></td>";
				$o_remotes .= "<td></td><td></td><td></td>";
				$o_remotes .= "<td>".my_scale($mount['size'], $unit)." $unit</td>";
				$o_remotes .= render_used_and_free($mount);

				$o_remotes .= "<td><a title='"._("View Remote SMB")."/"._("NFS Script Log")."' href='/Main/ScriptLog?d=".$mount['device']."'><i class='fa fa-align-left".( $mount['command'] ? "":" grey-orb" )."'></i></a></td>";
				$o_remotes .= "</tr>";

				/* Add to the share names. */
				$share_names[$mount['name']] = $mount_point;
			}
		}

		/* ISO file Mounts. */
		$iso_mounts = get_iso_mounts();
		if (count($iso_mounts)) {
			foreach ($iso_mounts as $mount) {
				$mounted = $mount['mounted'];
				$is_alive = is_file($mount['file']);
				$o_remotes .= "<tr>";
				$o_remotes .= sprintf( "<td><a class='info'><i class='fa fa-circle orb %s'></i><span>"._("ISO File is")." %s</span></a>iso</td>", ( $is_alive ? "green-orb" : "grey-orb" ), ( $is_alive ? _("online") : _("offline") ));
				$devname = basename($mount['device']);
				$o_remotes .= "<td>{$mount['device']}</td>";
				$mount_point = basename($mount['mountpoint']);
				if ($mounted) {
					$o_remotes .= "<td><i class='fa fa-external-link mount-share'></i><a title='"._("Browse ISO File Share")."' href='/Main/Browse?dir={$mount['mountpoint']}'>{$mount_point}</a></td>";
				} else {
					$o_remotes .= "<td>
						<i class='fa fa-pencil mount-share'></i>
						<a title='"._("Change ISO File Mount Point")."' class='exec' onclick='chg_iso_mountpoint(\"{$mount['device']}\",\"{$mount_point}\");'>{$mount_point}</a>
						</td>";
				}
				$disabled = $is_alive ? "enabled":"disabled";
				if ($mount['mounted'] && (is_script_running($mount['command']) || is_script_running($mount['user_command'], true))) {
					$o_remotes .= "<td><button class='mount' disabled> <i class='fa fa-spinner fa-spin'></i> "._('Running')."</button></td>";
				} else {
					/* Remove special characters. */
					$mount_device	= safe_name(basename($mount['device']));
					$is_mounting	= array_values(preg_grep("@/mounting_".$mount_device."@i", listDir(dirname($paths['mounting']))))[0];
					$is_mounting	= (time() - filemtime($is_mounting) < 300) ? true : false;
					$is_unmounting	= array_values(preg_grep("@/unmounting_".$mount_device."@i", listDir(dirname($paths['unmounting']))))[0];
					$is_unmounting	= (time() - filemtime($is_unmounting) < 300) ? true : false;
					if ($is_mounting) {
						$o_remotes .= "<td><button class='mount' disabled><i class='fa fa-spinner fa-spin'></i> "._('Mounting')."</button></td>";
					} else if ($is_unmounting) {
						$o_remotes .= "<td><button class='mount' disabled><i class='fa fa-spinner fa-spin'></i> "._('Unmounting')."</button></td>";
					} else {
						$o_remotes .= "<td>".($mounted ? "<button class='mount' device='{$mount['device']}' onclick=\"disk_op(this, 'umount','{$mount['device']}');\"><i class='fa fa-export'></i>"._('Unmount')."</button>" : "<button class='mount' device='{$mount['device']}' onclick=\"disk_op(this, 'mount','{$mount['device']}');\" {$disabled}><i class='fa fa-import'></i>"._('Mount')."</button>")."</td>";
					}
				}
				$o_remotes .= $mounted ? "<td><i class='fa fa-remove hdd'></i></td>" : "<td><a class='exec info' style='color:#CC0000;font-weight:bold;' onclick='remove_iso_config(\"{$mount['device']}\");'> <i class='fa fa-remove hdd'></i><span>"._("Remove ISO File Share")."</span></a></td>";

				$title = _("Edit ISO File Settings and Script");
				$title .= "<br />"._("Automount").": ";
				$title .= ($mount['automount'] == 'yes') ? "Yes" : "No";

				$o_remotes .= "<td><a class='info' href='/Main/EditDeviceSettings?i=".$mount['device']."&l=".$mount_point."'><i class='fa fa-gears'></i><span style='text-align:left'>$title</span></a></td>";
				$o_remotes .= "<td></td><td></td><td></td>";
				$o_remotes .= "<td>".my_scale($mount['size'], $unit)." $unit</td>";
				$o_remotes .= render_used_and_free($mount);
				$o_remotes .= "<td><a title='"._("View ISO File Script Log")."' href='/Main/ScriptLog?i=".$mount['device']."'><i class='fa fa-align-left".( $mount['command'] ? "":" grey-orb" )."'></i></a></td>";
				$o_remotes .= "</tr>";

				/* Add to the share names. */
				$share_names[$mount['device']] = $mount_point;
			}
		}
		if (! count($samba_mounts) && ! count($iso_mounts)) {
			$o_remotes .= "<tr><td colspan='13' style='text-align:center;'>"._('No Remote SMB')."/"._('NFS or ISO File Shares configured').".</td></tr>";
		}

		/* Historical devices. */
		$o_historical = "";
		$config_file	= $paths["config_file"];
		$config			= is_file($config_file) ? @parse_ini_file($config_file, true) : array();
		ksort($config, SORT_NATURAL);
		$disks_serials	= array();
		foreach ($all_disks as $disk) {
			$disks_serials[] = $disk['serial'];
		}

		/* Organize the historical devices. */
		$historical = array();
		foreach ($config as $serial => $value) {
			if ($serial != "Config") {
				if (! preg_grep("#{$serial}#", $disks_serials)){
					if (($config[$serial]['unassigned_dev']) && (! in_array($config[$serial]['unassigned_dev'], $disk_names))) {
						$disk_names[$serial] = $config[$serial]['unassigned_dev'];
					}
					$mntpoint		= basename($config[$serial]['mountpoint.1']);
					$mountpoint		= ($mntpoint) ? " (".$mntpoint.")" : "";
					$disk_dev		= $config[$serial]['unassigned_dev'];
					$disk_display	= _("none");
					if ($disk_dev) {
						$disk_display = $disk_dev;
					}
					$historical[$serial]['display']			= $disk_display;
					$historical[$serial]['mntpoint']		= $mntpoint;
					$historical[$serial]['mountpoint']		= $mountpoint;
				}
			}
		}
		ksort($historical, SORT_NATURAL);

		/* Display the historical devices. */
		foreach ($historical as $serial => $value) {
			$o_historical .= "<tr><td><i class='fa fa-minus-circle orb grey-orb'></i>".$historical[$serial]['display']."</td><td>".$serial.$historical[$serial]['mountpoint']."</td>";
			$o_historical .= "<td></td>";
			$o_historical .= "<td><a style='color:#CC0000;font-weight:bold;cursor:pointer;' class='exec info' onclick='remove_disk_config(\"{$serial}\")'><i class='fa fa-remove hdd'></i><span>"._("Remove Device Configuration")."</span></a></td>";
			$o_historical .= "<td><a class='info' href='/Main/EditDeviceSettings?s=".$serial."&l=".$historical[$serial]['mntpoint']."&p="."1"."&t=true'><i class='fa fa-gears'></i><span>"._("Edit Historical Device Settings and Script")."</span></a></td>";
			$o_historical .= "<td></td><td></td><td></td><td></td><td></td></tr>";
		}

		/* Save the current disk names for a duplicate check. */
		MiscUD::save_json($paths['disk_names'], $disk_names);

		/* Save the UD share names for duplicate check. */
		MiscUD::save_json($paths['share_names'], $share_names);

		echo json_encode(array("disks" => $o_disks, "remotes" => $o_remotes, "historical" => $o_historical, "buttons" => $o_buttons));
		break;

	case 'refresh_page':
		/* Initiate a nchan event to update the UD webpage. */
		publish();
		break;

	case 'update_ping':
		/* Refresh the ping status in the background. */
		exec("/usr/local/emhttp/plugins/{$plugin}/scripts/get_ud_stats ping &");

		publish();
		break;

	case 'get_content_json':
		/* Get the UD disk info and return in a json format. */
		$all_disks	= get_all_disks_info();
		echo json_encode($all_disks);
		break;

	/*	CONFIG	*/
	case 'automount':
		/* Update auto mount configuration setting. */
		$serial = urldecode($_POST['device']);
		$status = urldecode($_POST['status']);
		echo json_encode(array( 'result' => toggle_automount($serial, $status) ));
		break;

	case 'show_partitions':
		/* Update show partitions configuration setting. */
		$serial = urldecode($_POST['serial']);
		$status = urldecode($_POST['status']);
		echo json_encode(array( 'result' => set_config($serial, "show_partitions", ($status == "true") ? "yes" : "no")));
		break;

	case 'background':
		/* Update background configuration setting. */
		$device	= urldecode($_POST['device']);
		$part	= urldecode($_POST['part']);
		$status = urldecode($_POST['status']) == "yes" ? "true" : "false";
		echo json_encode(array( 'result' => set_config($device, "command_bg.{$part}", $status)));
		break;

	case 'set_command':
		/* Set the user command configuration setting. */
		$serial		= urldecode($_POST['serial']);
		$part		= urldecode($_POST['part']);
		$cmd		= urldecode($_POST['command']);
		$user_cmd	= urldecode($_POST['user_command']);
		$file_path = pathinfo($cmd);
		if (($file_path['dirname'] == "/boot/config/plugins/unassigned.devices") && ($file_path['filename'])) {
			set_config($serial, "user_command.{$part}", $user_cmd);
			echo json_encode(array( 'result' => set_config($serial, "command.{$part}", $cmd)));
		} else {
			if ($cmd) {
				unassigned_log("Warning: Cannot use '{$cmd}' as a device script file name.");
			}
			echo json_encode(array( 'result' => false));
		}
		break;

	case 'set_volume':
		/* Set apfs volume configuration setting. */
		$serial	= urldecode($_POST['serial']);
		$part	= urldecode($_POST['part']);
		$vol	= urldecode($_POST['volume']);
		echo json_encode(array( 'result' => set_config($serial, "volume.{$part}", $vol)));
		break;

	case 'set_name':
		/* Set disk name configuration setting. */
		$serial		= urldecode($_POST['serial']);
		$dev		= urldecode($_POST['device']);
		$name		= safe_name(urldecode($_POST['name']), true);
		$disk_names = MiscUD::get_json($paths['disk_names']);

		/* Remove our disk name. */
		unset($disk_names[$dev]);
		unset($disk_names[$serial]);

		/* Is the name already being used? */
		if (! in_array($name, $disk_names)) {
			if ((! $name) || (strpos($name, "dev") === false) && (strpos($name, "sd") === false)) {
				if (! $name) {
					$name	= get_disk_dev($dev);
				}
				$ser	= $serial;
				$ser	.= $dev ?  " (".$dev.")" : "";
				unassigned_log("Changed Disk Name on '".$ser."' to '".$name."'");
				echo json_encode(array( 'result' => set_config($serial, "unassigned_dev", $name)));
			} else {
				echo json_encode(array( 'result' => false));
			}
		} else {
			unassigned_log("Error: Disk Name '".$name."' is already being used on another device.");
			echo json_encode(array( 'result' => false));
		}
		break;

	case 'remove_config':
		/* Remove historical disk configuration. */
		$serial	= urldecode($_POST['serial']);
		echo json_encode(remove_config_disk($serial));
		break;

	case 'toggle_share':
		/* Toggle the share configuration setting. */
		$info	= json_decode(html_entity_decode($_POST['info']), true);
		$status	= urldecode($_POST['status']);
		$result	= toggle_share($info['serial'], $info['part'],$status);
		if ($result && $info['target']) {
			add_smb_share($info['mountpoint']);
			add_nfs_share($info['mountpoint']);
		} else if ($info['mounted']) {
			rm_smb_share($info['mountpoint']);
			rm_nfs_share($info['mountpoint']);
		}
		echo json_encode(array( 'result' => $result));
		break;

	case 'toggle_read_only':
		/* Toggle the disk read only configuration setting. */
		$serial	= urldecode($_POST['serial']);
		$status	= urldecode($_POST['status']);
		echo json_encode(array( 'result' => toggle_read_only($serial, $status) ));
		break;

	case 'toggle_pass_through':
		/* Toggle the disk pass through configuration setting. */
		$serial	= urldecode($_POST['serial']);
		$status	= urldecode($_POST['status']);
		echo json_encode(array( 'result' => toggle_pass_through($serial, $status) ));
		break;

	case 'toggle_disable_mount':
		/* Toggle the disable mount button setting. */
		$serial	= urldecode($_POST['device']);
		$status	= urldecode($_POST['status']);
		echo json_encode(array( 'result' => toggle_disable_mount($serial, $status) ));
		break;

	/*	DISK	*/
	case 'mount':
		/* Mount a disk device. */
		$device	= urldecode($_POST['device']);
		exec("plugins/{$plugin}/scripts/rc.unassigned mount ".escapeshellarg($device)." &>/dev/null", escapeshellarg($out), escapeshellarg($return));
		echo json_encode(["status" => $return ? false : true ]);
		break;

	case 'umount':
		/* Unmount a disk device. */
		$device	= urldecode($_POST['device']);
		exec("plugins/{$plugin}/scripts/rc.unassigned umount ".escapeshellarg($device)." &>/dev/null", escapeshellarg($out), escapeshellarg($return));
		echo json_encode(["status" => $return ? false : true ]);
		break;

	case 'rescan_disks':
		/* Refresh all disk partition information, update config files from flash, and clear status files. */
		exec("plugins/{$plugin}/scripts/copy_config.sh");

		$sf		= $paths['dev_state'];
		if (is_file($sf)) {
			$devs = @parse_ini_file($sf, true);
			foreach ($devs as $d) {
				$device = "/dev/".$d['device'];

				/* Refresh partition information. */
				exec("/usr/sbin/partprobe -d ".escapeshellarg($device));
			}
		}

		unassigned_log("Refreshed Disks and Configuration.");
		unassigned_log("Rescan Disks: initiated a Hotplug event.", $UDEV_DEBUG);

		/* Set flag to tell Unraid to update devs.ini file of unassigned devices. */
		sleep(1);
		@file_put_contents($paths['hotplug_event'], "");
		break;

	case 'format_disk':
		/* Format a disk. */
		$device	= urldecode($_POST['device']);
		$fs		= urldecode($_POST['fs']);
		$pass	= urldecode($_POST['pass']);
		@touch(sprintf($paths['formatting'],basename($device)));
		echo json_encode(array( 'status' => format_disk($device, $fs, $pass)));
		@unlink(sprintf($paths['formatting'],basename($device)));
		break;

	/*	SAMBA	*/
	case 'list_samba_hosts':
		/* Get a list of samba hosts. */
		$network	= $_POST['network'];
		$names		= [];
		foreach ($network as $iface) {
			$ip = $iface['ip'];
			$netmask = $iface['netmask'];
			exec("plugins/{$plugin}/scripts/port_ping.sh ".escapeshellarg($ip)." ".escapeshellarg($netmask)." 445", $hosts);
			foreach ($hosts as $host) {
				/* Look up the server name using nmblookup. */
				$name		= trim(shell_exec("/usr/bin/nmblookup -A ".escapeshellarg($host)." 2>/dev/null | grep -v 'GROUP' | grep -Po '[^<]*(?=<00>)' | head -n 1"));
				if (! $name) {
					/* If name doesn't resolve, see if it is a local name. */
					$name	= shell_exec("/sbin/arp -a ".escapeshellarg($host)." 2>&1 | grep -v 'arp:' | /bin/awk '{print $1}'");
					if ($name) {
						$n			= strpos($name, ".".$var['LOCAL_TLD']);
						if ($n !== false) {
							$name	= substr($name, 0, $n);
						}
						$name		= strtoupper($name);
					} else {
						if ($host == $_SERVER['SERVER_ADDR']) {
							$name	= strtoupper($var['NAME']);
						}
					}
				}
				$names[] = $name ? $name : $host;
			}
		}
		natsort($names);
		echo implode(PHP_EOL, $names);
		break;

	case 'list_samba_shares':
		/* Get a list of samba shares for a specific host. */
		$ip		= urldecode($_POST['IP']);
		$user	= isset($_POST['USER']) ? $_POST['USER'] : NULL;
		$pass	= isset($_POST['PASS']) ? $_POST['PASS'] : NULL;
		$domain	= isset($_POST['DOMAIN']) ? $_POST['DOMAIN'] : NULL;
		@file_put_contents("{$paths['authentication']}", "username=".$user."\n");
		@file_put_contents("{$paths['authentication']}", "password=".$pass."\n", FILE_APPEND);
		@file_put_contents("{$paths['authentication']}", "domain=".$domain."\n", FILE_APPEND);

		/* Update this server status before listing shares. */
		exec("/usr/local/emhttp/plugins/{$plugin}/scripts/get_ud_stats is_online $ip");

		/* Get a list of samba shares on this server. */
		$list	= shell_exec("/usr/bin/smbclient -t2 -g -L ".escapeshellarg($ip)." --authentication-file=".escapeshellarg($paths['authentication'])." 2>/dev/null | /usr/bin/awk -F'|' '/Disk/{print $2}' | sort");

		/* Shred the authentication file. */
		exec("/bin/shred -u ".escapeshellarg($paths['authentication']));
		echo $list;
		break;

	/*	NFS	*/
	case 'list_nfs_hosts':
		/* Get a list of nfs hosts. */
		$names	= [];
		$network = $_POST['network'];
		foreach ($network as $iface) {
			$ip = $iface['ip'];
			$netmask = $iface['netmask'];
			exec("/usr/bin/timeout -s 13 5 plugins/{$plugin}/scripts/port_ping.sh ".escapeshellarg($ip)." ".escapeshellarg($netmask)." 2049 2>/dev/null | sort -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4", $hosts);
			foreach ($hosts as $host) {
				/* Resolve name as a local server. */
				$name	= shell_exec("/sbin/arp -a ".escapeshellarg($host)." 2>&1 | grep -v 'arp:' | /bin/awk '{print $1}'");
				if ($name) {
					$n			= strpos($name, ".".$var['LOCAL_TLD']);
					if ($n !== false) {
						$name	= substr($name, 0, $n);
					}
					$name		= strtoupper($name);
				} else {
					if ($host == $_SERVER['SERVER_ADDR']) {
						$name	= strtoupper($var['NAME']);
					}
				}
				$names[] = $name ? $name : $host;
			}
		}
		natsort($names);
		echo implode(PHP_EOL, $names);
		break;

	case 'list_nfs_shares':
		/* Get a list of nfs shares for a specific host. */
		$ip		= urldecode($_POST['IP']);

		/* Update this server status before listing shares. */
		exec("/usr/local/emhttp/plugins/{$plugin}/scripts/get_ud_stats is_online $ip");

		/* List the shares. */
		$rc		= timed_exec(10, "/usr/sbin/showmount --no-headers -e ".escapeshellarg($ip)." 2>/dev/null | rev | cut -d' ' -f2- | rev | sort");
		echo $rc ? $rc : " ";
		break;

	/* SMB SHARES */
	case 'add_samba_share':
		/* Add a samba share configuration. */
		$rc		= true;

		$ip			= urldecode($_POST['IP']);
		$ip			= implode("",explode("\\", $ip));
		$ip			= stripslashes(trim($ip));
		$protocol	= urldecode($_POST['PROTOCOL']);
		$user		= isset($_POST['USER']) ? urldecode($_POST['USER']) : "";
		$domain		= isset($_POST['DOMAIN']) ? urldecode($_POST['DOMAIN']) : "";
		$pass		= isset($_POST['PASS']) ? urldecode($_POST['PASS']) : "";
		$path		= isset($_POST['SHARE']) ? urldecode($_POST['SHARE']) : "";
		$path		= implode("",explode("\\", $path));
		$path		= safe_name(stripslashes(trim($path)), false);
		$share		= basename($path);
		if ($share) {
			$device	= ($protocol == "NFS") ? "{$ip}:{$path}" : "//".strtoupper($ip)."/{$share}";
			$device	= str_replace("$", "", $device);
			set_samba_config("{$device}", "protocol", $protocol);
			set_samba_config("{$device}", "ip", (MiscUD::is_ip($ip) ? $ip : strtoupper($ip)));
			set_samba_config("{$device}", "path", $path);
			if ($protocol == "SMB") {
				set_samba_config("{$device}", "user", $user);
				set_samba_config("{$device}", "domain", $domain);
				set_samba_config("{$device}", "pass", encrypt_data($pass));
			}
			set_samba_config("{$device}", "share", safe_name($share, false));
		}
		echo json_encode($rc);
		break;

	case 'remove_samba_config':
		/* Remove samba configuration. */
		$device		= urldecode($_POST['device']);
		echo json_encode(remove_config_samba($device));
		break;

	case 'samba_automount':
		/* Set samba auto mount configuration setting. */
		$device		= urldecode($_POST['device']);
		$status		= urldecode($_POST['status']);
		echo json_encode(array( 'result' => toggle_samba_automount($device, $status) ));
		break;

	case 'toggle_samba_share':
		/* Toggle samba share configuration setting. */
		$info		= json_decode(html_entity_decode($_POST['info']), true);
		$status		= urldecode($_POST['status']);
		$result		= toggle_samba_share($info['device'], $status);
		if ($result && $info['target']) {
			add_smb_share($info['mountpoint']);
			add_nfs_share($info['mountpoint']);
		} else if ($info['mounted']) {
			rm_smb_share($info['mountpoint']);
			rm_nfs_share($info['mountpoint']);
		}
		echo json_encode(array( 'result' => $result));
		break;

	case 'toggle_samba_disable_mount':
		/* Toggle the disable mount button setting. */
		$device	= urldecode($_POST['device']);
		$status	= urldecode($_POST['status']);
		echo json_encode(array( 'result' => toggle_samba_disable_mount($device, $status) ));
		break;

	case 'samba_background':
		/* Set samba share background configuration setting. */
		$device		= urldecode($_POST['device']);
		$status		= urldecode($_POST['status']) == "yes" ? "true" : "false";
		echo json_encode(array( 'result' => set_samba_config($device, "command_bg", $status)));
		break;

	case 'set_samba_command':
		/* Set samba share user command configuration setting. */
		$device		= urldecode($_POST['device']);
		$cmd		= urldecode($_POST['command']);
		set_samba_config($device, "user_command", urldecode($_POST['user_command']));
		echo json_encode(array( 'result' => set_samba_config($device, "command", $cmd)));
		break;

	/* ISO FILE SHARES */
	case 'add_iso_share':
		/* Add iso file share. */
		$rc			= true;
		$file		= isset($_POST['ISO_FILE']) ? urldecode($_POST['ISO_FILE']) : "";
		$file 		= implode("",explode("\\", $file));
		$file		= stripslashes(trim($file));
		if (is_file($file)) {
			$info = pathinfo($file);
			$share	= $info['filename'];
			set_iso_config("{$file}", "file", $file);
			set_iso_config("{$file}", "share", $share);
		} else {
			unassigned_log("ISO File '{$file}' not found.");
			$rc		= false;
		}
		echo json_encode($rc);
		break;

	case 'remove_iso_config':
		/* Remove the iso share configuration. */
		$device = urldecode($_POST['device']);
		echo json_encode(remove_config_iso($device));
		break;

	case 'iso_automount':
		/* Set the iso auto mount configuration setting. */
		$device		= urldecode($_POST['device']);
		$status		= urldecode($_POST['status']);
		echo json_encode(array( 'result' => toggle_iso_automount($device, $status) ));
		break;

	case 'iso_background':
		/* Set the background configuration setting. */
		$device		= urldecode($_POST['device']);
		$status		= urldecode($_POST['status']) == "yes" ? "true" : "false";
		echo json_encode(array( 'result' => set_iso_config($device, "command_bg", $status)));
		break;

	case 'set_iso_command':
		/* Set the iso command file configuration setting. */
		$device		= urldecode($_POST['device']);
		$cmd		= urldecode($_POST['command']);
		echo json_encode(array( 'result' => set_iso_config($device, "command", $cmd)));
		break;

	/* ROOT SHARES */
	case 'add_root_share':
		/* Add iso file share. */
		$share		= urldecode($_POST['share']);
		$mountpoint	= urldecode($_POST['mountpoint']);
		$ip			= strtoupper($var['NAME']);
		$device		= "//".$ip.$mountpoint;

		if (! get_samba_config("{$device}", "protocol")) {
			set_samba_config("{$device}", "protocol", "ROOT");
			set_samba_config("{$device}", "ip", $ip);
			set_samba_config("{$device}", "path", $share);
			set_samba_config("{$device}", "share", safe_name($share, false));

			echo json_encode(true);
		} else {
			unassigned_log("Error: Root Share already assigned to '".$mountpoint."'!");
			echo json_encode(false);
		}
		break;

	case 'remove_root_config':
		/* Remove the iso share configuration. */
		$device = urldecode($_POST['device']);
		echo json_encode(remove_config_samba($device));
		break;

	case 'root_automount':
		/* Set the iso auto mount configuration setting. */
		$device		= urldecode($_POST['device']);
		$status		= urldecode($_POST['status']);
		echo json_encode(array( 'result' => toggle_samba_automount($device, $status) ));
		break;

	/*	MISC */
	case 'rm_partition':
		/* Remove a partition from a disk. */
		$serial		= urldecode($_POST['serial']);
		$device		= urldecode($_POST['device']);
		$partition	= urldecode($_POST['partition']);

		/* A disk can't be set to automount. */
		if (is_automount($serial)) {
			toggle_automount($serial, false);
		}
		echo json_encode(remove_partition($device, $partition));
		break;

	case 'clr_disk':
		/* Remove all partitions from a disk. */
		$serial		= urldecode($_POST['serial']);
		$device		= urldecode($_POST['device']);

		/* A disk can't be set to automount. */
		if (is_automount($serial)) {
			toggle_automount($serial, false);
		}
		echo json_encode(remove_all_partitions($device));
		break;

	case 'spin_down_disk':
		/* Spin down a disk device. */
		$device		= urldecode($_POST['device']);

		/* Set the spinning_down state. */
		$tc			= $paths['run_status'];
		$run_status	= file_exists($tc) ? json_decode(file_get_contents($tc), true) : array();
		if ($run_status[$device]['running'] == 'yes') {
			$run_status[$device]['spin_time'] = time();
			$run_status[$device]['spin'] = 'down';
			@file_put_contents($tc, json_encode($run_status));
			echo json_encode(MiscUD::spin_disk(true, $device));
		}
		break;

	case 'spin_up_disk':
		/* Spin up a disk device. */
		$device		= urldecode($_POST['device']);

		/* Set the spinning_up state. */
		$tc			= $paths['run_status'];
		$run_status	= file_exists($tc) ? json_decode(file_get_contents($tc), true) : array();
		if ($run_status[$device]['running'] == 'no') {
			$run_status[$device]['spin_time'] = time();
			$run_status[$device]['spin'] = 'up';
			@file_put_contents($tc, json_encode($run_status));
			echo json_encode(MiscUD::spin_disk(false, $device));
		}
		break;

	case 'chg_mountpoint':
		/* Change a disk mount point. */
		$serial			= urldecode($_POST['serial']);
		$partition		= urldecode($_POST['partition']);
		$device			= urldecode($_POST['device']);
		$fstype			= urldecode($_POST['fstype']);
		$mountpoint		= basename(safe_name(urldecode($_POST['mountpoint']), false));
		echo json_encode(change_mountpoint($serial, $partition, $device, $fstype, $mountpoint));
		break;

	case 'chg_samba_mountpoint':
		/* Change a samba share mount point. */
		$device			= urldecode($_POST['device']);
		$mountpoint		= basename(safe_name(basename(urldecode($_POST['mountpoint'])), false));
		echo json_encode(change_samba_mountpoint($device, $mountpoint));
		break;

	case 'chg_iso_mountpoint':
		/* Change an iso file mount point. */
		$device			= urldecode($_POST['device']);
		$mountpoint		= basename(safe_name(basename(urldecode($_POST['mountpoint'])), false));
		echo json_encode(change_iso_mountpoint($device, $mountpoint));
		break;

	default:
		unassigned_log("Undefined POST action - ".$_POST['action'].".");
		break;
	}
?>
