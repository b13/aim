CREATE TABLE tx_aim_configuration (
    ai_provider varchar(255) DEFAULT '' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    description text,
    `default` tinyint(4) unsigned DEFAULT '0' NOT NULL,
    api_key varchar(255) DEFAULT '' NOT NULL,
    model varchar(255) DEFAULT '' NOT NULL,
    total_cost double(10,6) DEFAULT '0.000000' NOT NULL,
    cost_currency varchar(10) DEFAULT 'USD' NOT NULL,

    max_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    input_token_cost double(10,6) DEFAULT '0.000000' NOT NULL,
    output_token_cost double(10,6) DEFAULT '0.000000' NOT NULL,
    be_groups varchar(255) DEFAULT '' NOT NULL,
    privacy_level varchar(20) DEFAULT 'standard' NOT NULL,
    rerouting_allowed tinyint(1) unsigned DEFAULT '1' NOT NULL,
    auto_model_switch tinyint(1) unsigned DEFAULT '1' NOT NULL
);

CREATE TABLE tx_aim_usage_budget (
    uid int(11) unsigned NOT NULL auto_increment,
    user_id int(11) unsigned DEFAULT '0' NOT NULL,
    period_start int(11) unsigned DEFAULT '0' NOT NULL,
    period_type varchar(20) DEFAULT 'monthly' NOT NULL,
    tokens_used int(11) unsigned DEFAULT '0' NOT NULL,
    cost_used double(10,6) DEFAULT '0.000000' NOT NULL,
    requests_used int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY user_period (user_id, period_type)
);

CREATE TABLE tx_aim_request_log (
	uid int(11) unsigned NOT NULL auto_increment,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	request_type varchar(255) DEFAULT '' NOT NULL,
	provider_identifier varchar(255) DEFAULT '' NOT NULL,
	configuration_uid int(11) unsigned DEFAULT '0' NOT NULL,
	model_requested varchar(255) DEFAULT '' NOT NULL,
	model_used varchar(255) DEFAULT '' NOT NULL,
	extension_key varchar(255) DEFAULT '' NOT NULL,
	success tinyint(1) unsigned DEFAULT '0' NOT NULL,
	prompt_tokens int(11) unsigned DEFAULT '0' NOT NULL,
	completion_tokens int(11) unsigned DEFAULT '0' NOT NULL,
	cached_tokens int(11) unsigned DEFAULT '0' NOT NULL,
	reasoning_tokens int(11) unsigned DEFAULT '0' NOT NULL,
	total_tokens int(11) unsigned DEFAULT '0' NOT NULL,
	cost double(10,6) DEFAULT '0.000000' NOT NULL,
	duration_ms int(11) unsigned DEFAULT '0' NOT NULL,
	system_fingerprint varchar(255) DEFAULT '' NOT NULL,
	error_message text,
	metadata text,
	raw_usage text,
	user_id int(11) unsigned DEFAULT '0' NOT NULL,
	request_prompt text,
	request_system_prompt text,
	response_content text,
	complexity_score double(5,4) DEFAULT '0.0000' NOT NULL,
	complexity_label varchar(20) DEFAULT '' NOT NULL,
	complexity_reason text,
	rerouted tinyint(1) unsigned DEFAULT '0' NOT NULL,
	reroute_type varchar(20) DEFAULT '' NOT NULL,
	reroute_reason varchar(255) DEFAULT '' NOT NULL,

	PRIMARY KEY (uid),
	KEY crdate (crdate),
	KEY provider_identifier (provider_identifier),
	KEY extension_key (extension_key),
	KEY configuration_uid (configuration_uid),
	KEY user_id (user_id),
	KEY model_used (model_used),
	KEY request_type (request_type)
);
