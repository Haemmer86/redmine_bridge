<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Settings;

use OCA\RedmineBridge\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {

	public function __construct(
		private readonly IL10N $l,
		private readonly IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Redmine');
	}

	public function getPriority(): int {
		return 76;
	}

	public function getIcon(): string {
		return $this->url->imagePath(Application::APP_ID, 'app.svg');
	}
}
