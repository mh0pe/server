<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\encryption\tests;

use OC\Files\ObjectStore\ObjectStoreStorage;
use OC\Files\ObjectStore\StorageObjectStore;
use OC\Files\Storage\Temporary;
use OC\Files\Storage\Wrapper\Encryption;
use OC\Files\View;
use OCA\Encryption\KeyManager;
use OCP\Files\Mount\IMountManager;
use OCP\Files\ObjectStore\IObjectStore;
use OCP\Files\Storage\IDisableEncryptionStorage;
use OCP\Server;
use Test\TestCase;
use Test\Traits\EncryptionTrait;
use Test\Traits\MountProviderTrait;
use Test\Traits\UserTrait;

class TemporaryNoEncrypted extends Temporary implements IDisableEncryptionStorage {

}

class ObjectStoreNoEncrypted extends ObjectStoreStorage implements IDisableEncryptionStorage {

}

#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class EncryptedStorageTest extends TestCase {
	use MountProviderTrait;
	use EncryptionTrait;
	use UserTrait;

	public function testMoveFromEncrypted(): void {
		Server::get(KeyManager::class)->validateMasterKey();
		Server::get(KeyManager::class)->validateShareKey();
		$this->createUser('test1', 'test2');
		$this->setupForUser('test1', 'test2');

		$unwrapped = new Temporary();

		$this->registerMount('test1', new TemporaryNoEncrypted(), '/test1/files/unenc');
		$this->registerMount('test1', $unwrapped, '/test1/files/enc');

		$this->loginWithEncryption('test1');

		$view = new View('/test1/files');

		/** @var IMountManager $mountManager */
		$mountManager = Server::get(IMountManager::class);

		$encryptedMount = $mountManager->find('/test1/files/enc');
		$unencryptedMount = $mountManager->find('/test1/files/unenc');
		$encryptedStorage = $encryptedMount->getStorage();
		$unencryptedStorage = $unencryptedMount->getStorage();
		$encryptedCache = $encryptedStorage->getCache();
		$unencryptedCache = $unencryptedStorage->getCache();

		$this->assertTrue($encryptedStorage->instanceOfStorage(Encryption::class));
		$this->assertFalse($unencryptedStorage->instanceOfStorage(Encryption::class));

		$encryptedStorage->file_put_contents('foo.txt', 'bar');
		$this->assertEquals('bar', $encryptedStorage->file_get_contents('foo.txt'));
		$this->assertStringStartsWith('HBEGIN:oc_encryption_module:', $unwrapped->file_get_contents('foo.txt'));

		$this->assertTrue($encryptedCache->get('foo.txt')->isEncrypted());

		$view->rename('enc/foo.txt', 'unenc/foo.txt');

		$this->assertEquals('bar', $unencryptedStorage->file_get_contents('foo.txt'));
		$this->assertFalse($unencryptedCache->get('foo.txt')->isEncrypted());
	}

	/**
	 * Moving between two object store storages that share an object store takes a
	 * metadata only shortcut in ObjectStoreStorage::moveFromStorage. That shortcut must
	 * not be taken for an encrypted source, otherwise the ciphertext stays in the object
	 * store while the cache entry loses its `encrypted` mark, and the unencrypted mount
	 * has no encryption wrapper left to decrypt it.
	 */
	public function testMoveFromEncryptedObjectStore(): void {
		[
			'view' => $view,
			'objectStore' => $objectStore,
			'unencryptedStorage' => $unencryptedStorage,
		] = $this->setUpSharedObjectStoreMounts();

		$view->file_put_contents('enc/foo.txt', 'bar');
		$this->assertEquals('bar', $view->file_get_contents('enc/foo.txt'));

		$view->rename('enc/foo.txt', 'unenc/foo.txt');

		$this->assertEquals('bar', $view->file_get_contents('unenc/foo.txt'));
		$this->assertFalse($unencryptedStorage->getCache()->get('foo.txt')->isEncrypted());
		$this->assertStringStartsNotWith(
			'HBEGIN:',
			$this->readRawObject($objectStore, $unencryptedStorage, 'foo.txt'),
			'the object was moved verbatim and is still encrypted at rest'
		);
		// a move must not leave the source behind, neither on disk nor in the cache
		$this->assertFalse($view->file_exists('enc/foo.txt'), 'the source file still exists after the move');
	}

	/**
	 * Same as above for the copy shortcut in ObjectStoreStorage::copyFromStorage, which
	 * hands the ciphertext to the object store's server side copy.
	 */
	public function testCopyFromEncryptedObjectStore(): void {
		[
			'view' => $view,
			'objectStore' => $objectStore,
			'unencryptedStorage' => $unencryptedStorage,
		] = $this->setUpSharedObjectStoreMounts();

		$view->file_put_contents('enc/foo.txt', 'bar');

		$view->copy('enc/foo.txt', 'unenc/foo.txt');

		$this->assertEquals('bar', $view->file_get_contents('enc/foo.txt'));
		$this->assertEquals('bar', $view->file_get_contents('unenc/foo.txt'));
		$this->assertFalse($unencryptedStorage->getCache()->get('foo.txt')->isEncrypted());
		$this->assertStringStartsNotWith(
			'HBEGIN:',
			$this->readRawObject($objectStore, $unencryptedStorage, 'foo.txt'),
			'the object was copied verbatim and is still encrypted at rest'
		);
	}

	/**
	 * Two object store storages backed by the same object store, one mounted with and one
	 * without the encryption wrapper.
	 *
	 * @return array{view: View, objectStore: IObjectStore, unencryptedStorage: ObjectStoreStorage}
	 */
	private function setUpSharedObjectStoreMounts(): array {
		Server::get(KeyManager::class)->validateMasterKey();
		Server::get(KeyManager::class)->validateShareKey();
		$this->createUser('test1', 'test2');
		$this->setupForUser('test1', 'test2');

		// sharing the object store instance is what makes the storage ids match and
		// enables the metadata only shortcut
		$objectStore = new StorageObjectStore(new Temporary());
		$encrypted = new ObjectStoreStorage(['objectstore' => $objectStore, 'storageid' => 'test-enc']);
		$unencrypted = new ObjectStoreNoEncrypted(['objectstore' => $objectStore, 'storageid' => 'test-unenc']);

		$this->registerMount('test1', $encrypted, '/test1/files/enc');
		$this->registerMount('test1', $unencrypted, '/test1/files/unenc');

		$this->loginWithEncryption('test1');

		return [
			'view' => new View('/test1/files'),
			'objectStore' => $objectStore,
			'unencryptedStorage' => $unencrypted,
		];
	}

	private function readRawObject(IObjectStore $objectStore, ObjectStoreStorage $storage, string $path): string {
		$fileId = $storage->getCache()->get($path)->getId();
		$handle = $objectStore->readObject($storage->getURN($fileId));
		$content = stream_get_contents($handle);
		fclose($handle);

		return $content;
	}
}
