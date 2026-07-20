<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace OC\Avatar;

use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\Files\SimpleFS\InMemoryFile;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class RemoteAvatar extends Avatar {
	private ICloudId $cloudId;

	public function __construct(
		protected ISimpleFolder $folder,
		protected string $userId,
		protected IConfig $config,
		protected LoggerInterface $logger,
	) {
		parent::__construct($config, $logger);

		$cloudIdManager = \OCP\Server::get(ICloudIdManager::class);
		$this->cloudId = $cloudIdManager->resolveCloudId($userId);
	}

	#[\Override]
	public function exists(): bool {
		return true;
	}

	#[\Override]
	public function getDisplayName(): string {
		return $this->cloudId->getDisplayId();
	}

	/**
	 * Setting avatars isn't implemented for remote accounts
	 */
	public function set($data): void {
	}

	/**
	 * Removing avatars isn't implemented for remote accounts
	 */
	public function remove(bool $silent = false): void {
	}

	#[\Override]
	public function getFile(int $size, bool $darkTheme = false): ISimpleFile {
		$avatarExists = false;
		if ($size === -1) {
			$filename = 'avatar' . ($darkTheme ? '-dark' : '');
		} else {
			$filename = 'avatar' . ($darkTheme ? '-dark' : '') . '.' . $size;
		}

		// check first if a png avatar exists
		$ext = 'png';
		$avatarExists = $this->folder->fileExists($filename . '.' . $ext);
		// if the png avatar doesn't exist, check if there's a jpg
		if (!$avatarExists) {
			$ext = 'jpg';
			$avatarExists = $this->folder->fileExists($filename . '.' . $ext);
		}

		if ($avatarExists) {
			$file = $this->folder->getFile($filename . '.' . $ext);

			// check if a remote avatar is at least a day old, in case a new avatar was uploaded
			$isAvatarOld = (time() - $file->getMTime()) >= (60 * 60 * 24);
			if (!$isAvatarOld) {
				return $file;
			}
		}

		$url = rtrim($this->cloudId->getRemote(), '/') . '/index.php/avatar/' . rawurlencode($this->cloudId->getUser()) . '/' . $size;
		if ($darkTheme) {
			$url .= '/dark';
		}

		$clientService = \OCP\Server::get(IClientService::class);
		$client = $clientService->newClient();
		$response = $client->get($url, [
			'verify' => !$this->config->getSystemValueBool('sharing.federation.allowSelfSignedCertificates', false)
		]);

		$contentType = $response->getHeader('Content-Type');
		if ($contentType !== 'image/png' && $contentType !== 'image/jpeg') {
			throw new \Exception('Unknown filetype');
		}

		$avatar = $response->getBody();
		if (is_resource($avatar)) {
			$avatar = stream_get_contents($avatar);
			if ($avatar === false) {
				throw new \Exception('Failed to fetch remote avatar');
			}
		}

		$ext = $contentType === 'image/jpg' ? 'jpg' : 'png';
		return $this->folder->newFile($filename . '.' . $ext, $avatar);
	}

	/**
	 * Handling user changes isn't implemented for remote accounts
	 */
	#[\Override]
	public function userChanged(string $feature, $oldValue, $newValue): void {
	}

	#[\Override]
	public function isCustomAvatar(): bool {
		return true;
	}
}
