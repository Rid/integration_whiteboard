<?php
/**
 * Nextcloud - spacedeck
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2021
 */

namespace OCA\Spacedeck\Service;

use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\IConfig;
use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\Lock\LockedException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Http\Client\IClientService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ConnectException;
use OCP\Http\Client\LocalServerException;
use OCP\IUser;
use OCP\IUserManager;

use GuzzleHttp;

use OCA\Spacedeck\Service\SpacedeckBundleService;
use OCA\Spacedeck\AppInfo\Application;

require_once __DIR__ . '/../constants.php';

class SpacedeckAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to Spacedeck API
	 */
	public function __construct (string $appName,
								IRootFolder $root,
								IUserManager $userManager,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								SpacedeckBundleService $spacedeckBundleService,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->config = $config;
		$this->root = $root;
		$this->userManager = $userManager;
		$this->clientService = $clientService;
		// $this->client = $clientService->newClient();
		$this->client = new GuzzleHttp\Client();
		$this->spacedeckBundleService = $spacedeckBundleService;
	}

	/**
	 * Trigger Spacedeck pdf export
	 *
	 * @param string $baseUrl
	 * @param string $apiToken
	 * @param ?string $userId
	 * @param string $space_id
	 * @param int $file_id
	 * @return array success state
	 */
	public function exportSpaceToPdf(string $baseUrl, string $apiToken, string $userId, int $file_id, string $outputDirPath,
									bool $usesIndexDotPhp): array {
		$spaceFile = $this->getFileFromId($userId, $file_id);
		if ($spaceFile) {
			if ($baseUrl === DEFAULT_SPACEDECK_URL) {
				$this->spacedeckBundleService->launchSpacedeck($usesIndexDotPhp);
			}
			$spaceFileName = $spaceFile->getName();
			$targetFileName = preg_replace('/\.whiteboard$/', '.pdf', $spaceFileName);
			$userFolder = $this->root->getUserFolder($userId);

			try {
				$outputDir = $userFolder->get($outputDirPath);
			} catch (NotFoundException $e) {
				return ['error' => 'Output dir not found'];
			}
			if ($outputDir->getType() !== FileInfo::TYPE_FOLDER || ($outputDir->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
				return ['error' => 'Not enough permissions'];
			}
			if ($outputDir->nodeExists($targetFileName)) {
				$targetFile = $outputDir->get($targetFileName);
				if ($targetFile->getType() !== FileInfo::TYPE_FILE) {
					return ['error' => 'Target file is a directory'];
				}
			} else {
				try {
					$targetFile = $outputDir->newFile($targetFileName);
				} catch (NotPermittedException $e) {
					return ['error' => 'Not enough permissions'];
				}
			}

			try {
				$res = $targetFile->fopen('w');
			} catch (LockedException $e) {
				return ['error' => 'File is locked'];
			}

			$response = $this->apiRequest($baseUrl, $apiToken, 'spaces/' . $file_id . '/pdf');
			if (isset($response['error'])) {
				return $response;
			} elseif (!isset($response['url'])) {
				return ['error' => 'Invalid spacedeck response'];
			}

			$path = $response['url'];
			$url = $baseUrl . $path;
			$result = $this->basicRequest($url);
			$strContent = $result['response']->getBody();

			fwrite($res, $strContent);
			fclose($res);
			$targetFile->touch();
			return [
				'ok' => 1,
				'name' => $targetFileName,
			];
		} else {
			return ['error' => 'File does not exist'];
		}
	}

	/**
	 * Save a space content in a file
	 *
	 * @param string $baseUrl
	 * @param string $apiToken
	 * @param ?string $userId
	 * @param string $space_id
	 * @param int $file_id
	 * @return array success state
	 */
	public function saveSpaceToFile(string $baseUrl, string $apiToken, ?string $userId, string $space_id, int $file_id): array {
		$targetFile = $this->getFileFromId($userId, $file_id);
		if ($targetFile) {
			try {
				$res = $targetFile->fopen('w');
			} catch (LockedException $e) {
				return ['error' => 'File is locked'];
			}

			// directly get json artifacts and json space through API
			// endpoints:
			// * GET spaces/space_id
			// * GET spaces/space_id/artifacts
			// write { space: space_response, artifacts: artifacts_response }
			$space = $this->apiRequest($baseUrl, $apiToken, 'spaces/' . $space_id);
			if (isset($space['error'])) {
				return $space;
			}
			$artifacts = $this->apiRequest($baseUrl, $apiToken, 'spaces/' . $space_id . '/artifacts');
			if (isset($artifacts['error'])) {
				return $artifacts;
			}
			$content = [
				'space' => $space,
				'artifacts' => $artifacts,
			];
			$strContent = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

			fwrite($res, $strContent);
			fclose($res);
			$targetFile->touch();
			return ['ok' => 1];
		} else {
			return ['error' => 'File does not exist'];
		}
	}

	/**
	 * Try to load the space from a file
	 * If the space exists, just return its ID and edit hash
	 * If the space does NOT exist, create it and load the file content
	 *
	 * @param string $baseUrl
	 * @param string $apiToken
	 * @param ?string $userId
	 * @param int $file_id
	 * @return array error or space information
	 */
	public function loadSpaceFromFile(string $baseUrl, string $apiToken, ?string $userId, int $file_id, bool $usesIndexDotPhp): array {
		if ($baseUrl === DEFAULT_SPACEDECK_URL) {
			$pid = $this->spacedeckBundleService->launchSpacedeck($usesIndexDotPhp);
		}
		// load file json content
		$file = $this->getFileFromId($userId, $file_id);
		if (is_null($file)) {
			return ['error' => 'File does not exist'];
		}
		$fileContent = trim($file->getContent());

		// file is empty: create a space
		if (!$fileContent) {
			$newSpace = $this->createSpace($baseUrl, $apiToken, $userId, $file_id);
			if (isset($newSpace['error'])) {
				return $newSpace;
			}
			// write it to the file to update space_id
			$decoded['space']['_id'] = $newSpace['_id'];
			$decoded['space']['edit_hash'] = $newSpace['edit_hash'];
			$decoded['space']['edit_slug'] = $newSpace['edit_slug'];
			$decoded['space']['name'] = $newSpace['name'];
			$file->putContent(json_encode($decoded));
			return [
				'existed' => false,
				'base_url' => $baseUrl,
				'space_id' => $newSpace['_id'],
				'space_name' => $newSpace['name'],
				'edit_hash' => $newSpace['edit_hash'],
			];
		}

		// file is not empty, try to load it
		try {
			$decoded = json_decode($fileContent, true);
		} catch (Exception | Throwable $e) {
			return ['error' => 'File is invalid, impossible to parse JSON'];
		}
		if (isset($decoded['space'], $decoded['space']['_id'])) {
			$spaceId = $decoded['space']['_id'];
		} else {
			return ['error' => 'File is invalid, no "_id"'];
		}
		// check if space_id exists: GET spaces/space_id
		$space = $this->apiRequest($baseUrl, $apiToken, 'spaces/' . $spaceId);
		if (isset($space['error'], $space['errorType']) && $space['errorType'] === 'LocalServerException') {
			return $space;
		}
		// does not exist or wrong file ID
		if (isset($space['error']) || $decoded['space']['name'] !== strval($file_id)) {
			// create new space
			$newSpace = $this->createSpace($baseUrl, $apiToken, $userId, $file_id);
			if (isset($newSpace['error'])) {
				return $newSpace;
			}
			// load artifacts
			if (isset($decoded['artifacts']) && is_array($decoded['artifacts'])) {
				foreach ($decoded['artifacts'] as $artifact) {
					$artifact['space_id'] = $newSpace['_id'];
					$artifact['user_id'] = null;
					$this->loadArtifact($baseUrl, $apiToken, $newSpace['_id'], $artifact);
				}
			}
			// write it to the file to update space_id
			$decoded['space']['_id'] = $newSpace['_id'];
			$decoded['space']['edit_hash'] = $newSpace['edit_hash'];
			$decoded['space']['edit_slug'] = $newSpace['edit_slug'];
			$decoded['space']['name'] = $newSpace['name'];
			$file->putContent(json_encode($decoded));
			return [
				'existed' => false,
				'base_url' => $baseUrl,
				'space_id' => $newSpace['_id'],
				'space_name' => $newSpace['name'],
				'edit_hash' => $newSpace['edit_hash'],
			];
		} else {
			// exists
			return [
				'existed' => true,
				'base_url' => $baseUrl,
				'space_id' => $space['_id'],
				'space_name' => $space['name'],
				'edit_hash' => $space['edit_hash'],
			];
		}
	}

	/**
	 * Make the request to load an artifact from JSON
	 *
	 * @param string $baseUrl
	 * @param string $apiToken
	 * @param string $spaceId
	 * @param array $artifact
	 * @return void
	 */
	private function loadArtifact(string $baseUrl, string $apiToken, string $spaceId, array $artifact): void {
		$response = $this->apiRequest($baseUrl, $apiToken, 'spaces/' . $spaceId . '/artifacts', $artifact, 'POST');
		if (isset($response['error'])) {
			$this->logger->error('Error creating artifact in ' . $spaceId . ' : ' . $response['error']);
		}
	}

	/**
	 * Make the request to create a space
	 *
	 * @param string $baseUrl
	 * @param string $apiToken
	 * @param ?string $userId
	 * @param int $fileId
	 * @return array new space request result
	 */
	private function createSpace(string $baseUrl, string $apiToken, ?string $userId, int $fileId): array {
		$strFileId = strval($fileId);
		$params = [
			'name' => $strFileId,
			'edit_slug' => $strFileId,
		];
		return $this->apiRequest($baseUrl, $apiToken, 'spaces', $params, 'POST');
	}

	/**
	 * Get a user file from a fileId
	 *
	 * @param ?string $userId
	 * @param int $fileID
	 * @return ?Node the file or null if it does not exist (or is not accessible by this user)
	 */
	public function getFileFromId(?string $userId, int $fileId): ?Node {
		if (is_null($userId)) {
			$file = $this->root->getById($fileId);
		} else {
			$userFolder = $this->root->getUserFolder($userId);
			$file = $userFolder->getById($fileId);
		}
		if (is_array($file) && count($file) > 0) {
			return $file[0];
		} elseif (!is_array($file) && $file->getType() === FileInfo::TYPE_FILE) {
			return $file;
		}
		return null;
	}

	/**
	 * Check if user has write access on a file
	 *
	 * @param ?string $userId
	 * @param int $fileID
	 * @return bool true if the user can write the file
	 */
	public function userHasWriteAccess(string $userId, int $fileId): bool {
		$userFolder = $this->root->getUserFolder($userId);
		$file = $userFolder->getById($fileId);
		if (is_array($file)) {
			foreach ($file as $f) {
				if ($f->getType() === FileInfo::TYPE_FILE && ($f->getPermissions() & Constants::PERMISSION_UPDATE) !== 0) {
					return true;
				}
			}
		} elseif (!is_array($file) && $file->getType() === FileInfo::TYPE_FILE) {
			return (($file->getPermissions() & Constants::PERMISSION_UPDATE) !== 0);
		}
		return false;
	}

	/**
	 * Get spaces list from spacedeck API
	 *
	 * @param string $baseUrl
	 * @param string $apiToken
	 * @return array API response or request error
	 */
	public function getSpaceList(string $baseUrl, string $apiToken, bool $usesIndexDotPhp): array {
		if ($baseUrl === DEFAULT_SPACEDECK_URL) {
			$this->spacedeckBundleService->launchSpacedeck($usesIndexDotPhp);
		}
		return $this->apiRequest($baseUrl, $apiToken, 'spaces');
	}

	/**
	 * Delete storage data that is not used anymore:
	 * - everything related to spaces that have no corresponding file
	 * - data of artifacts that don't exist anymore
	 * This should be done by spacedeck...but it's not ATM
	 *
	 * @param string $baseUrl
	 * @param string $apiToken
	 * @return array with status and errors
	 */
	public function cleanupSpacedeckStorage(string $baseUrl, string $apiToken): array {
		// we don't try to launch spacedeck here because urlGenerator->getBaseUrl()
		// gives a bad result (path is missing) in a job/command context
		$spaces = $this->apiRequest($baseUrl, $apiToken, 'spaces');
		if (isset($spaces['error'])) {
			return ['error' => 'Spacedeck is unreachable, it might not be running. ' . $spaces['error']];
		}
		$actions = [];
		// get all whiteboard file IDs
		$fileIds = [];
		$this->userManager->callForSeenUsers(function (IUser $user) use (&$fileIds) {
			$userFolder = $this->root->getUserFolder($user->getUID());
			$wbFiles = $userFolder->searchByMime('application/spacedeck');
			foreach ($wbFiles as $wbFile) {
				if (!in_array($wbFile->getId())) {
					$fileIds[] = $wbFile->getId();
				}
			}
		});
		// check all spaces in spacedeck
		foreach ($spaces as $space) {
			$spaceId = $space['_id'];
			$spaceName = $space['name'];
			$spaceEditSlug = $space['edit_slug'];
			// this does not work in the "Command" context because the storage is not set
			// if ($this->getFileFromId(null, (int) $spaceName) === null) {
			if (!in_array((int) $spaceName, $fileIds)) {
				// file does not exist
				// => delete all data
				$this->spacedeckBundleService->deleteSpaceStorage($spaceId);
				$actions[] = 'Deleted storage of space ' . $spaceId;
				// => and delete the space via the API
				$response = $this->apiRequest($baseUrl, $apiToken, 'spaces/' . $spaceId, [], 'DELETE');
				if (isset($response['error'])) {
					$this->logger->error('Error deleting space ' . $spaceId . ' : ' . $response['error']);
				} else {
					$actions[] = 'Deleted space ' . $spaceId;
				}
			} else {
				// file exist: check if storage artifact data should be deleted
				$artifacts = $this->apiRequest($baseUrl, $apiToken, 'spaces/' . $spaceId . '/artifacts');
				if (isset($artifacts['error'])) {
					$this->logger->error('Error getting artifacts of space ' . $spaceId . ' : ' . $artifacts['error']);
				} else {
					$artifactIds = [];
					foreach ($artifacts as $artifact) {
						$artifactIds[] = $artifact['_id'];
					}
					$deletedIds = $this->spacedeckBundleService->cleanArtifactStorage($spaceId, $artifactIds);
					foreach ($deletedIds as $id) {
						$actions[] = 'Space ' . $spaceId . ' artifact ' . $id . ' deleted';
					}
				}
			}
		}
		return ['actions' => $actions];
	}

	/**
	 * Perform a Spacedeck API HTTP request
	 *
	 * @param string $baseUrl
	 * @param string $apiToken
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @return array json decoded response or error
	 */
	private function apiRequest(string $baseUrl, string $apiToken, string $endPoint, array $params = [], string $method = 'GET'): array {
		try {
			$url = $baseUrl . '/api/' . $endPoint;
			$options = [
				'headers' => [
					'X-Spacedeck-API-Token' => $apiToken,
					'User-Agent' => 'Nextcloud Spacedeck integration',
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['headers']['Content-Type'] = 'application/json';
					$options['body'] = json_encode($params);
				}
			}

			if ($method === 'GET') {
				$response = $this->client->request('GET', $url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->request('POST', $url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->request('PUT', $url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->request('DELETE', $url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => 'Bad credentials'];
			} else {
				return json_decode($body, true) ?? [];
			}
		} catch (ServerException | ClientException $e) {
			$response = $e->getResponse();
			// $this->logger->warning('Spacedeck API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		} catch (ConnectException $e) {
			$this->logger->warning('Spacedeck request connection error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		} catch (LocalServerException $e) {
			$this->logger->warning('Spacedeck request LocalServerException : '.$e->getMessage(), ['app' => $this->appName]);
			return [
				'error' => 'Nextcloud refuses to connect to local remote servers',
				'errorType' => 'LocalServerException',
			];
		}
	}

	/**
	 * Perform a basic HTTP request
	 *
	 * @param string $url
	 * @param array $params
	 * @param string $method
	 * @param bool $jsonOutput
	 * @param array $extraHeaders
	 * @param ?string $stringBody
	 * @return array depending on $jsonOutput: json decoded response or text response body or error
	 */
	public function basicRequest(string $url, array $params = [], string $method = 'GET',
								bool $jsonOutput = false, array $extraHeaders = [], ?string $stringBody = null): array {
		try {
			$options = [
				'headers' => [
					'User-Agent' => 'Nextcloud Spacedeck integration',
				],
			];
			foreach ($extraHeaders as $key => $val) {
				$options['headers'][$key] = $val;
			}

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['headers']['Content-Type'] = 'application/json';
					$options['body'] = json_encode($params);
				}
			} elseif ($stringBody) {
				$options['body'] = $stringBody;
			}

			if ($method === 'GET') {
				$response = $this->client->request('GET', $url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->request('POST', $url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->request('PUT', $url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->request('DELETE', $url, $options);
			}
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => 'Bad credentials'];
			} else {
				if ($jsonOutput) {
					$body = $response->getBody();
					return json_decode($body, true);
				} else {
					return ['response' => $response];
				}
			}
		} catch (ServerException | ClientException $e) {
			$response = $e->getResponse();
			// $this->logger->warning('Spacedeck API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		} catch (ConnectException $e) {
			$this->logger->warning('Spacedeck request connection error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		} catch (LocalServerException $e) {
			$this->logger->warning('Spacedeck request LocalServerException : '.$e->getMessage(), ['app' => $this->appName]);
			return [
				'error' => 'Nextcloud refuses to connect to local remote servers',
				'errorType' => 'LocalServerException',
			];
		}
	}
}
