<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['minimum_target_php_version'] = '8.1';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'], [
		'../../extensions/CreateWiki',
		'../../extensions/Echo',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'], [
		'../../extensions/CreateWiki',
		'../../extensions/Echo',
	]
);

$cfg['suppress_issue_types'] = [
	'PhanAccessMethodInternal',
];

$cfg['plugins'][] = __DIR__ . '/../vendor/miraheze/phan-plugins/NoOptionalParamPlugin.php';

return $cfg;
