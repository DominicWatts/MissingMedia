<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Xigen\MissingMedia\Console\Command;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\File\Csv;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\ProgressBarFactory;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Missing extends Command
{
    const ENABLED_ARGUMENT = 'enabled';
    const FILE_PATH = 'xigen/no-product-image-export';
    const FILE_EXT = '.csv';
    const ROW_DELIMITER = ",";
    const ROW_ENCLOSURE = '"';
    const ROW_END = "\n";

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

        $this->mediaPath = $this->filesystem
            ->getDirectoryRead(DirectoryList::MEDIA)
            ->getAbsolutePath();

        $this->imagePath = $this->mediaPath . 'catalog' . DIRECTORY_SEPARATOR . 'product';

        $this->exportPath = $this->mediaPath . self::FILE_PATH . '_' . date('Y_m_d') . self::FILE_EXT;

        $enabledOnly = $this->input->getArgument(self::ENABLED_ARGUMENT) ?: false;

        $this->output->writeln((string) __(
            '[%1] Start',
            $this->dateTime->gmtDate()
        ));

        $this->output->writeln((string) __(
            '[%1] Fetching no media products with %2 status',
            $this->dateTime->gmtDate(),
            $enabledOnly ? 'enabled' : 'any'
        ));

        $mediaEntries = $this->getProductsWithNoMedia($enabledOnly);

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
            $table->setHeaders(['SKU', 'EntityId', 'Type']);
        }

        $missing = [];
        foreach ($mediaEntries as $entry) {
            $progress->setMessage((string) __(
                'Product [%1] : %2 ',
                $entry['entity_id'] ?? null,
                $entry['sku']
            ));

            $missing[] = [
                'sku' => $entry['sku'] ?? null,
                'entity_id' => $entry['entity_id'] ?? null,
                'type_id' => $entry['type_id'] ?? null
            ];

            if ($output->getVerbosity() !== OutputInterface::VERBOSITY_NORMAL) {
                $table->addRow([
                    'sku' => $entry['sku'] ?? null,
                    'entity_id' => $entry['entity_id'] ?? null,
                    'type_id' => $entry['type_id'] ?? null
                ]);
            }

            $progress->advance();
        }

        $this->generateFile($missing, self::ROW_ENCLOSURE, self::ROW_DELIMITER, $this->exportPath);

        $progress->finish();
        $this->output->writeln('');

        if ($output->getVerbosity() !== OutputInterface::VERBOSITY_NORMAL) {
            $table->render();
        }

        $this->output->writeln((string) __(
            '[%1] Finish',
            $this->dateTime->gmtDate()
        ));
    }

    /**
     * get status attribute ID
     * @return array
     */
    public function getStatusAttributeId()
    {
        $ea = $this->resource->getTableName('eav_attribute');
        $eet = $this->resource->getTableName('eav_entity_type');
        $select = $this->connection->select()->from(
            ['ea' => $ea],
            ['ea.attribute_id']
        )->joinLeft(
            ['eet' => $eet],
            'ea.entity_type_id = eet.entity_type_id',
            ['']
        )->where(
            'ea.attribute_code =?',
            ProductAttributeInterface::CODE_STATUS
        )->where(
            'eet.entity_type_code =?',
            ProductAttributeInterface::ENTITY_TYPE_CODE
        );

        $data = $this->connection->fetchOne($select);
        return (int) $data ?? null;
    }

    /**
     * Find products with no media entries
     * @return array
     */
    public function getProductsWithNoMedia($enabledOnly = false)
    {
        $cpe = $this->resource->getTableName('catalog_product_entity');
        $cpemg = $this->resource->getTableName('catalog_product_entity_media_gallery');
        $cpemgvte = $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $select = $this->connection->select()->from(
            ['cpe' => $cpe],
            ['cpe.sku', 'cpe.entity_id', 'cpe.type_id']
        )->joinLeft(
            ['cpemgvte' => $cpemgvte],
            'cpe.entity_id = cpemgvte.entity_id',
            ['']
        )->joinLeft(
            ['cpemg' => $cpemg],
            'cpemgvte.value_id = cpemg.value_id',
            ['count' => 'count(cpemg.value)']
        )->group(
            'cpe.entity_id'
        )->having(
            'count = 0'
        )->order(
            'cpe.type_id',
            SortOrder::SORT_ASC
        )->order(
            'cpe.sku',
            SortOrder::SORT_ASC
        );

        if ($enabledOnly == true) {
            $cpei = $this->resource->getTableName('catalog_product_entity_int');
            $select->joinLeft(
                ['cpei' => $cpei],
                'cpe.entity_id = cpei.entity_id',
                ['']
            )->where(
                'cpei.attribute_id = ?',
                $this->getStatusAttributeId()
            )->where(
                'cpei.store_id = ?',
                Store::DEFAULT_STORE_ID
            )->where(
                'cpei.value = ?',
                Status::STATUS_ENABLED
            );
        }

        $data = $this->connection->fetchAll($select);
        return $data ?? [];
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
     * xigen:missingmedia:missing [--] <enabled>
     */
    protected function configure()
    {
        $this->setName("xigen:missingmedia:missing");
        $this->setDescription("Find products with no images");
        $this->setDefinition([
            new InputArgument(self::ENABLED_ARGUMENT, InputArgument::OPTIONAL, 'Generate'),
        ]);
        parent::configure();
    }
}
