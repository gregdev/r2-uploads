<?php

namespace R2_Uploads;

use Aws\S3\Transfer;
use Aws\Command;
use Exception;
use WP_CLI;

class WP_CLI_Command extends \WP_CLI_Command
{
	/**
	 * Verifies the API keys entered will work for writing and deleting from R2.
	 *
	 * @subcommand verify
	 */
	public function verify_api_keys()
	{
		// Verify first that we have the necessary access keys to connect to R2.
		if (!$this->verify_r2_access_constants()) {
			return;
		}

		// Get R2 Upload instance.
		$instance = Plugin::get_instance();

		// Create a path in the base directory, with a random file name to avoid potentially overwriting existing data.
		$upload_dir = wp_upload_dir();
		$s3_path = $upload_dir['basedir'] . '/' . wp_rand() . '.txt';

		// Attempt to copy the local Canola test file to the generated path on R2.
		WP_CLI::print_value('Attempting to upload file ' . $s3_path);

		$copy = copy(
			dirname(dirname(__FILE__)) . '/verify.txt',
			$s3_path
		);

		// Check that the copy worked.
		if (!$copy) {
			WP_CLI::error('Failed to copy / write to R2 - check your policy?');

			return;
		}

		WP_CLI::print_value('File uploaded to R2 successfully.');

		// Delete the file off S3.
		WP_CLI::print_value('Attempting to delete file. ' . $s3_path);
		$delete = unlink($s3_path);

		// Check that the delete worked.
		if (!$delete) {
			WP_CLI::error('Failed to delete ' . $s3_path);

			return;
		}

		WP_CLI::print_value('File deleted from R2 successfully.');

		WP_CLI::success('Looks like your configuration is correct.');
	}

	/**
	 * List files in the R2 bucket
	 *
	 * @synopsis [<path>]
	 *
	 * @param array{0: string} $args
	 */
	public function ls(array $args)
	{
		$s3 = Plugin::get_instance()->s3();

		$prefix = '';

		if (strpos(R2_UPLOADS_BUCKET, '/')) {
			$prefix = trailingslashit(str_replace(strtok(R2_UPLOADS_BUCKET, '/') . '/', '', R2_UPLOADS_BUCKET));
		}

		if (isset($args[0])) {
			$prefix .= trailingslashit(ltrim($args[0], '/'));
		}

		try {
			$objects = $s3->getIterator(
				'ListObjectsV2', [
					'Bucket' => strtok(R2_UPLOADS_BUCKET, '/'),
					'Prefix' => $prefix,
				]
			);
			/** @var array{Key: string} $object */
			foreach ($objects as $object) {
				WP_CLI::line(str_replace($prefix, '', $object['Key']));
			}
		} catch (Exception $e) {
			WP_CLI::error($e->getMessage());
		}
	}

	/**
	 * Copy files to / from the uploads directory. Use s3://bucket/location for R2
	 *
	 * @synopsis <from> <to>
	 *
	 * @param array{0: string, 1: string} $args
	 */
	public function cp(array $args)
	{
		$from = $args[0];
		$to = $args[1];

		if (is_dir($from)) {
			$this->recurse_copy($from, $to);
		} else {
			copy($from, $to);
		}

		WP_CLI::success(sprintf('Completed copy from %s to %s', $from, $to));
	}

	/**
	 * Upload a directory to R2
	 *
	 * @subcommand upload-directory
	 * @synopsis <from> [<to>] [--concurrency=<concurrency>] [--verbose]
	 *
	 * @param array{0: string, 1: string} $args
	 * @param array{concurrency?: int, verbose?: bool} $args_assoc
	 */
	public function upload_directory(array $args, array $args_assoc)
	{
		$from = $args[0];
		$to = '';
		if (isset($args[1])) {
			$to = $args[1];
		}

		$s3 = Plugin::get_instance()->s3();
		$args_assoc = wp_parse_args(
			$args_assoc, [
				'concurrency' => 5,
				'verbose' => false,
			]
		);

		$transfer_args = [
			'concurrency' => $args_assoc['concurrency'],
			'debug' => (bool) $args_assoc['verbose'],
			'before' => function (Command $command) {
				if (in_array($command->getName(), ['PutObject', 'CreateMultipartUpload'], true)) {
					$acl = defined('R2_UPLOADS_OBJECT_ACL') ? R2_UPLOADS_OBJECT_ACL : 'public-read';
					$command['ACL'] = $acl;
				}
			},
		];
		try {
			$manager = new Transfer($s3, $from, 's3://' . R2_UPLOADS_BUCKET . '/' . $to, $transfer_args);
			$manager->transfer();
		} catch (Exception $e) {
			WP_CLI::error($e->getMessage());
		}
	}

	/**
	 * Delete files from R2
	 *
	 * @synopsis <path> [--regex=<regex>]
	 *
	 * @param array{0: string} $args
	 * @param array{regex?: string} $args_assoc
	 */
	public function rm(array $args, array $args_assoc)
	{
		$s3 = Plugin::get_instance()->s3();

		$prefix = '';
		$regex = isset($args_assoc['regex']) ? $args_assoc['regex'] : '';

		if (strpos(R2_UPLOADS_BUCKET, '/')) {
			$prefix = trailingslashit(str_replace(strtok(R2_UPLOADS_BUCKET, '/') . '/', '', R2_UPLOADS_BUCKET));
		}

		if (isset($args[0])) {
			$prefix .= ltrim($args[0], '/');

			if (strpos($args[0], '.') === false) {
				$prefix = trailingslashit($prefix);
			}
		}

		try {
			$s3->deleteMatchingObjects(
				strtok(R2_UPLOADS_BUCKET, '/'),
				$prefix,
				$regex,
				[
					'before_delete',
					function () {
						WP_CLI::line(sprintf('Deleting file'));
					},
				]
			);
		} catch (Exception $e) {
			WP_CLI::error($e->getMessage());
		}

		WP_CLI::success(sprintf('Successfully deleted %s', $prefix));
	}

	/**
	 * Enable the auto-rewriting of media links to R2
	 */
	public function enable()
	{
		update_option('r2_uploads_enabled', 'enabled');

		WP_CLI::success('Media URL rewriting enabled.');
	}

	/**
	 * Disable the auto-rewriting of media links to R2
	 */
	public function disable()
	{
		delete_option('r2_uploads_enabled');

		WP_CLI::success('Media URL rewriting disabled.');
	}

	/**
	 * List all files for a given attachment.
	 *
	 * Useful for debugging.
	 *
	 * @subcommand get-attachment-files
	 * @synopsis <attachment-id>
	 *
	 * @param array{0: int} $args
	 */
	public function get_attachment_files(array $args)
	{
		WP_CLI::print_value(Plugin::get_attachment_files($args[0]));
	}

	/**
	 * Update the ACL of all files for an attachment.
	 *
	 * Useful for debugging.
	 *
	 * @subcommand set-attachment-acl
	 * @synopsis <attachment-id> <acl>
	 *
	 * @param array{0: int, 1: 'public-read'|'private'} $args
	 */
	public function set_attachment_acl(array $args)
	{
		$result = Plugin::get_instance()->set_attachment_files_acl($args[0], $args[1]);
		WP_CLI::print_value($result);
	}

	private function recurse_copy(string $src, string $dst)
	{
		$dir = opendir($src);
		@mkdir($dst);
		while (false !== ($file = readdir($dir))) {
			if (('.' !== $file) && ('..' !== $file)) {
				if (is_dir($src . '/' . $file)) {
					$this->recurse_copy($src . '/' . $file, $dst . '/' . $file);
				} else {
					WP_CLI::line(sprintf('Copying from %s to %s', $src . '/' . $file, $dst . '/' . $file));
					copy($src . '/' . $file, $dst . '/' . $file);
				}
			}
		}
		closedir($dir);
	}

	/**
	 * Verify that the required constants for the R2 connections are set.
	 *
	 * @return bool true if all constants are set, else false.
	 */
	private function verify_r2_access_constants()
	{
		$required_constants = [
			'R2_UPLOADS_BUCKET',
			'R2_UPLOADS_KEY',
			'R2_UPLOADS_SECRET',
		];

		$all_set = true;
		foreach ($required_constants as $constant) {
			if (!defined($constant)) {
				WP_CLI::error(sprintf('The required constant %s is not defined.', $constant), false);
				$all_set = false;
			}
		}

		return $all_set;
	}
}
