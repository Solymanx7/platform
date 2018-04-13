<?php defined('SYSPATH') or die('No direct script access');

/**
 * Ushahidi Webhook Console Command
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Console
 * @copyright  2014 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

use Ushahidi\Console\Command;
use Ushahidi\Core\Entity\PostExportRepository;
use Ushahidi\Core\Entity\ExportJobRepository;
use Ushahidi\Factory\DataFactory;
use Ushahidi\Core\UserContextService;
use Ushahidi\Core\Tool\FormatterTrait;

use Ushahidi\Core\Tool\Filesystem;
use Ushahidi\Core\Tool\FileData;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use League\Flysystem\Util\MimeType;
use Aura\Di\Container;

class Ushahidi_Console_PostExporter extends Command
{


	use FormatterTrait;

	private $data;
	private $postExportRepository;
	private $exportJobRepository;
	private $userRepository;
	private $fs;

	public function __construct()
	{
		parent::__construct();

	}

	public function setFileSystem(Filesystem $fs)
	{
		$this->fs = $fs;
	}

	public function setDatabase(Database $db)
	{
		$this->db = $db;
	}

	public function setExportJobRepo(ExportJobRepository $repo)
	{
		$this->exportJobRepository = $repo;
	}
//	public function setUser(UserContext $userContext)
//	{
//		$this->userContext = $userContext;
//	}
	public function setUserRepo(\Ushahidi\Core\Entity\UserRepository $repo)
	{
		$this->userRepository = $repo;
	}


	public function setDataFactory(DataFactory $data)
	{
		$this->data = $data;
	}

	public function setPostExportRepo(PostExportRepository $repo)
	{
		$this->postExportRepository = $repo;
	}

	protected function configure()
	{
		$this
			->setName('exporter')
			->setDescription('Export Posts')
			->addArgument('action', InputArgument::REQUIRED, 'list, export')
			->addOption('limit', ['l'], InputOption::VALUE_OPTIONAL, 'limit')
			->addOption('offset', ['o'], InputOption::VALUE_OPTIONAL, 'offset')
			->addOption('job', ['j'], InputOption::VALUE_OPTIONAL, 'job')
			->addOption('include_header', ['ih'], InputOption::VALUE_OPTIONAL, 'include_header')
		;
	}

	protected function executeList(InputInterface $input, OutputInterface $output)
	{
		return [
			[
				'Available actions' => 'export'
			]
		];
	}

	protected function executeExport(InputInterface $input, OutputInterface $output)
	{
		$userContextService = service('usercontext.service');
		// Construct a Search Data objec to hold the search info
		$data = $this->data->get('search');

		// Get CLI params
		$limit = $input->getOption('limit') ? $input->getOption('limit') : 100;
		$offset = $input->getOption('offset') ? $input->getOption('offset') : 0;
		$job_id = $input->getOption('job') ? $input->getOption('job') : null;
		$add_header = $input->getOption('include_header') ? $input->getOption('include_header') : true;

		// At the moment there is only CSV format
		$format = 'csv';

		// Set the baseline filter parameters
		$filters = [
			'limit' => $limit,
			'offset' => $offset,
			'exporter' => true
		];

		if ($job_id) {
			// Load the export job
			$job = $this->exportJobRepository->get($job_id);
			$user = $this->userRepository->get($job->user_id);
			$userContextService->setUser($user);
			// Merge the export job filters with the base filters
			if ($job->filters) {
				$filters = array_merge($filters, $job->filters);
			}

			// Set the fields that should be included if set
			if ($job->fields) {
				$data->include_attributes = $job->fields;
			}
		}

		foreach ($filters as $key => $filter) {
			$data->$key = $filter;
		}
		$this->postExportRepository->setSearchParams($data);
		$posts = $this->postExportRepository->getSearchResults();
		service("formatter.entity.post.$format")->setFileSystem($this->fs);
		service("formatter.entity.post.$format")->setAddHeader($add_header);
		//fixme add post_date
		$form_ids = $this->postExportRepository->getFormIdsForHeaders();
		$attributes = $this->postExportRepository->getAttributes($form_ids);

		$keyAttributes = [];
		foreach($attributes as $key => $item)
		{
			$keyAttributes[$item['key']] = $item;
		}

		// // ... remove any entities that cannot be seen
		foreach ($posts as $idx => $post) {
			// Retrieved Attribute Labels for Entity's values
			$post = $this->postExportRepository->retrieveMetaData($post->asArray(), $keyAttributes);
			$posts[$idx] = $post;
		}
		/**FIXME: how to make sure header_row is null/empty instead off an array with an empty item in it? */
		if (empty($job->header_row) || $job->header_row[0] == '') {

			$header_row = service("formatter.entity.post.$format")->createHeading($attributes, $posts);
			$job->setState(['header_row' => $header_row]);
            $this->exportJobRepository->update($job);
		} else {
			service("formatter.entity.post.$format")->setHeading($job->header_row);
		}

		$file = service("formatter.entity.post.$format")->__invoke($posts, $keyAttributes);

		$response = [
			[
				'file' => $file->file,
			]
		];

		$this->handleResponse($response, $output, 'json');
	}
}