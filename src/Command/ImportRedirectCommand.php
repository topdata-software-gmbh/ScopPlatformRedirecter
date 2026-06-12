<?php
declare(strict_types=1);

namespace Scop\PlatformRedirecter\Command;

use Shopware\Core\Content\ImportExport\ImportExportFactory;
use Shopware\Core\Content\ImportExport\ImportExportProfileEntity;
use Shopware\Core\Content\ImportExport\Service\ImportExportService;
use Shopware\Core\Content\ImportExport\Struct\Progress;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[AsCommand(
    name: 'scop:redirect:import',
    description: 'Import all CSV files from a directory as redirects',
)]
class ImportRedirectCommand extends Command
{
    private const TECHNICAL_NAME = 'default_scop_platform_redirecter_redirect';
    private const CHUNK_SIZE = 300;

    public function __construct(
        private readonly ImportExportService $importExportService,
        private readonly ImportExportFactory $importExportFactory,
        private readonly EntityRepository $profileRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('directory', InputArgument::REQUIRED, 'Path to directory containing CSV files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createCLIContext();

        $dir = rtrim((string) $input->getArgument('directory'), '/');
        if (!is_dir($dir)) {
            $io->error("Directory not found: $dir");
            return self::FAILURE;
        }

        $files = glob($dir . '/*.csv');
        if ($files === false || $files === []) {
            $io->warning("No CSV files found in $dir");
            return self::SUCCESS;
        }

        $profile = $this->getProfile($context);
        if ($profile === null) {
            $io->error('Default redirect import profile not found');
            return self::FAILURE;
        }

        $io->title(sprintf('Importing %d CSV files from %s', count($files), $dir));

        $totalImported = 0;
        $fileErrors = 0;

        foreach ($files as $filePath) {
            $filename = basename((string) $filePath);
            $io->section("Processing: $filename");

            try {
                $result = $this->importFile($filePath, $profile, $context, $io);
                $totalImported += $result['imported'];
                $io->success("$filename: {$result['imported']} records imported");
            } catch (\Throwable $e) {
                $fileErrors++;
                $io->error("$filename: " . $e->getMessage());
            }
        }

        $io->newLine();
        if ($fileErrors === 0) {
            $io->success("All $totalImported records imported successfully");
        } else {
            $io->warning("Imported $totalImported records ($fileErrors files with errors)");
        }

        return self::SUCCESS;
    }

    private function importFile(string $filePath, ImportExportProfileEntity $profile, Context $context, SymfonyStyle $io): array
    {
        $totalRows = $this->countCsvRows($filePath);

        $expireDate = new \DateTimeImmutable('+30 days');
        $file = new UploadedFile($filePath, basename($filePath), $profile->getFileType());

        $log = $this->importExportService->prepareImport(
            $context,
            $profile->getId(),
            $expireDate,
            $file,
            [],
            false
        );

        $importExport = $this->importExportFactory->create(
            $log->getId(),
            self::CHUNK_SIZE,
            self::CHUNK_SIZE,
        );

        $progressBar = $io->createProgressBar($totalRows);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed%/%remaining%');
        $progressBar->start();

        $progress = new Progress($log->getId(), Progress::STATE_PROGRESS, 0);
        do {
            $progress = $importExport->import($context, $progress->getOffset());
            $progressBar->setProgress($progress->getProcessedRecords());
        } while (!$progress->isFinished());

        $progressBar->finish();
        $io->newLine(2);

        return [
            'imported' => $progress->getProcessedRecords() ?? 0,
        ];
    }

    private function countCsvRows(string $filePath): int
    {
        $file = new \SplFileObject($filePath, 'r');
        $file->setFlags(\SplFileObject::READ_AHEAD | \SplFileObject::SKIP_EMPTY);
        $count = 0;
        while (!$file->eof()) {
            $file->fgets();
            $count++;
        }
        $count--;

        return max(0, $count);
    }

    private function getProfile(Context $context): ?ImportExportProfileEntity
    {
        $result = $this->profileRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('technicalName', self::TECHNICAL_NAME)),
            $context
        )->getEntities();

        return $result->first();
    }
}
