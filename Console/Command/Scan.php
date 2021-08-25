<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Xigen\MissingMedia\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\File\Csv;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\ProgressBarFactory;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Scan extends Command
{
    const FILE_PATH = 'xigen/missing-product-image-export.csv';
    const ROW_DELIMITER = ",";
    const ROW_ENCLOSURE = '"';
    const ROW_END = "\n";
    const DELETE_OPTION = 'delete';

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $dateTime;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var ProgressBarFactory
     */
    protected $progressBarFactory;

    /**
     * @var string
     */
    protected $mediaPath;

    /**
     * @var string
     */
    protected $imagePath;

    /**
     * @var \Magento\Framework\Filesystem
     */
    protected $filesystem;

    /**
     * @var \Magento\Framework\Filesystem\Driver\File;
     */
    protected $file;
    /**
     * @var Csv
     */
    private $csv;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private $connection;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var string
     */
    private $exportPath;

    /**
     * Scan function
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\App\State $state
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $dateTime
     * @param \Symfony\Component\Console\Helper\ProgressBarFactory $progressBarFactory
     * @param \Magento\Framework\File\Csv $csv
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\Filesystem\Driver\File $file
     * @param \Magento\Framework\App\ResourceConnection $resource
     */
    public function __construct(
        LoggerInterface $logger,
        State $state,
        DateTime $dateTime,
        ProgressBarFactory $progressBarFactory,
        Csv $csv,
        Filesystem $filesystem,
        File $file,
        ResourceConnection $resource
    ) {
        $this->logger = $logger;
        $this->state = $state;
        $this->dateTime = $dateTime;
        $this->progressBarFactory = $progressBarFactory;
        $this->csv = $csv;
        $this->filesystem = $filesystem;
        $this->file = $file;
        $this->connection = $resource->getConnection();
        $this->resource = $resource;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        $this->input = $input;
        $this->output = $output;
        $this->state->setAreaCode(Area::AREA_GLOBAL);

        $delete = $this->input->getOption(self::DELETE_OPTION) ? true : false;

        $this->mediaPath = $this->filesystem
            ->getDirectoryRead(DirectoryList::MEDIA)
            ->getAbsolutePath();

        $this->imagePath = $this->mediaPath . 'catalog' . DIRECTORY_SEPARATOR . 'product';

        $this->exportPath = $this->mediaPath . self::FILE_PATH;

        if ($delete) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                (string) __('You are about to delete product gallery data. Are you sure? [y/N]'),
                false
            );

            if (!$helper->ask($this->input, $this->output, $question) && $this->input->isInteractive()) {
                return Cli::RETURN_FAILURE;
            }
        }

        $this->output->writeln((string) __(
            '[%1] Start',
            $this->dateTime->gmtDate()
        ));

        $mediaEntries = $this->fetchMediaValueToEntity();

        $table = new Table($this->output);

        /** @var ProgressBar $progress */
        $progress = $this->progressBarFactory->create(
            [
                'output' => $this->output,
                'max' => count($mediaEntries)
            ]
        );

        $progress->setFormat(
            "%current%/%max% [%bar%] %percent:3s%% %elapsed% %memory:6s% \t| <info>%message%</info>"
        );

        if ($output->getVerbosity() !== OutputInterface::VERBOSITY_NORMAL) {
            $progress->setOverwrite(false);
            $table = new Table($this->output);
            $table->setHeaders(['SKU', 'EntityId', 'ValueId','Asset', 'Path']);
        }

        $missing = [];
        foreach ($mediaEntries as $entry) {
            $skuFetch = $this->resolveSkuById($entry['entity_id']);
            $sku = $skuFetch['sku'] ?? null;
            $mediaFetch = $this->fetchMediaEntryByValueId($entry['value_id']);
            $mediaValue = $mediaFetch['value'] ?? null;

            if ($mediaValue) {
                $progress->setMessage((string) __(
                    'Product [%1] : %2 ',
                    $entry['entity_id'] ?? null,
                    $sku
                ));

                if (!$this->file->isExists($this->imagePath . $mediaValue)) {
                    if ($delete) {
                        $this->removeMediaEntry($mediaFetch['value_id']);
                    } else {
                        $missing[] = [
                            'sku' => $sku,
                            'entity_id' => $entry['entity_id'] ?? null,
                            'value_id' => $mediaFetch['value_id'] ?? null,
                            'asset' => $mediaValue,
                            'path' => $this->imagePath . $mediaValue
                        ];
                    }

                    if ($output->getVerbosity() !== OutputInterface::VERBOSITY_NORMAL) {
                        $table->addRow([
                            $sku,
                            $entry['entity_id'] ?? null,
                            $mediaFetch['value_id'] ?? null,
                            $mediaValue,
                            $this->imagePath . $mediaValue
                        ]);
                    }
                }
            }
            $progress->advance();
        }

        if (!$delete) {
            $this->generateFile($missing, self::ROW_ENCLOSURE, self::ROW_DELIMITER, $this->exportPath);
        }

        $progress->finish();
        $this->output->writeln('');

        if ($output->getVerbosity() !== OutputInterface::VERBOSITY_NORMAL) {
            if ($delete) {
                $this->output->writeln((string) __(
                    '[%1] Removed the following entries',
                    $this->dateTime->gmtDate()
                ));
                $this->output->writeln('');
            }
            $table->render();
        }

        $this->output->writeln((string) __(
            '[%1] Finish',
            $this->dateTime->gmtDate()
        ));
    }

    /**
     * Fetch media entries
     * @return array
     */
    public function fetchMediaValueToEntity()
    {
        $tableName = $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $select = $this->connection
        ->select()
        ->from(
            ['cpemgvte' => $tableName]
        )
        ->order('cpemgvte.entity_id', 'ASC');
        $data = $this->connection->fetchAll($select);
        return $data;
    }

    /**
     * Fetch media entry by value ID
     * @param null $valueId
     * @return array
     */
    public function fetchMediaEntryByValueId($valueId = null)
    {
        $tableName = $this->resource->getTableName('catalog_product_entity_media_gallery');
        $select = $this->connection
            ->select()
            ->from(
                ['cpemg' => $tableName]
            )
            ->where('cpemg.value_id = ?', $valueId)
            ->where('cpemg.value != ""')
            ->where('cpemg.media_type = ?', 'image');

        $data = $this->connection->fetchAll($select);
        return $data[0] ?? null;
    }

    /**
     * Resolve SKU by entity ID
     * @param $entityId
     * @return array
     */
    public function resolveSkuById($entityId)
    {
        $tableName = $this->resource->getTableName('catalog_product_entity');
        $select = $this->connection
            ->select()
            ->from(
                ['cpe' => $tableName]
            )
            ->where('cpe.entity_id = ?', $entityId);

        $data = $this->connection->fetchAll($select);
        return $data[0] ?? null;
    }

    public function removeMediaEntry($valueId)
    {
        $tableName = $this->resource->getTableName('catalog_product_entity_media_gallery');
        $select = $this->connection
            ->select()
            ->from($tableName)
            ->where('value_id = ?', $valueId);

        try {
            // deliberate empty second argument
            $query = $this->connection->deleteFromSelect($select, []);
            $statement = $this->connection->query($query);
            return $statement->rowCount();
        } catch (\Exception $e) {
            $this->logger->critical($e);
        }
    }

    /**
     * Generate file
     * @param arary $entries
     * @param string $enclosure
     * @param string $delimeter
     * @param string $fileName
     */
    public function generateFile($entries, $enclosure, $delimeter, $fileName)
    {
        $fileObj = $this->csv;
        $fileObj->setLineLength(5000);
        $fileObj->setEnclosure($enclosure);
        $fileObj->setDelimiter($delimeter);

        if (!empty($entries)) {
            $headings = array_keys($entries[0]);
            $fileObj->saveData($fileName, [$headings]);
            foreach ($entries as $entry) {
                $dataRow = array_values($entry);
                $this->appendData($fileName, [$dataRow], 'a');
            }
        }
    }

    /**
     * Missing from current version of magento
     * Replace the saveData method by allowing to select the input mode
     * @param string $file
     * @param array $data
     * @param string $mode
     * @return $this
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function appendData($file, $data, $mode = 'w')
    {
        // phpcs:disable
        $fileHandler = fopen($file, $mode);
        foreach ($data as $dataRow) {
            $this->file->filePutCsv($fileHandler, $dataRow, self::ROW_DELIMITER, self::ROW_ENCLOSURE);
        }
        fclose($fileHandler);

        return $this;
        // phpcs:enable
    }

    /**
     * {@inheritdoc}
     * xigen:missingmedia:scan [-d|--delete DELETE]
     * xigen:missingmedia:scan -d 1
     */
    protected function configure()
    {
        $this->setName("xigen:missingmedia:scan");
        $this->setDescription("Scan media gallery entries for images that are missing");
        $this->setDefinition([
            new InputOption(self::DELETE_OPTION, '-d', InputOption::VALUE_OPTIONAL, 'Delete'),
        ]);
        parent::configure();
    }
}
