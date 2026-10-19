<?php declare(strict_types=1);

namespace Helret\HelloRetail\ScheduledTask;

use Helret\HelloRetail\Export\Profiles\ProfileExporterInterface;
use Helret\HelloRetail\HelretHelloRetail;
use Helret\HelloRetail\Service\ExportService;
use Helret\HelloRetail\Service\HelloRetailService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: HelloRetailTask::class)]
class HelloRetailHandler extends ScheduledTaskHandler
{
    public function __construct(
        protected EntityRepository $scheduledTaskRepository,
        protected ProfileExporterInterface $profileExporter,
        protected EntityRepository $salesChannelRepository,
        protected HelloRetailService $helloRetailService,
        protected SystemConfigService $configService
    ) {
        parent::__construct($scheduledTaskRepository);
    }

    public function run(): void
    {
        $salesChannelIds = $this->salesChannelRepository->searchIds(
            ExportService::getSalesChannelCriteria(),
            Context::createDefaultContext()
        )->getIds();

        foreach ($salesChannelIds as $salesChannelId) {
            try {
                /* check settings for each */
                $feeds = $this->getFeedsThatShouldRunNow($salesChannelId);
                if (count($feeds) > 0) {
                    $this->profileExporter->generate($salesChannelId, $feeds);
                }
            } catch (\Error|\TypeError|\Exception $e) {
                $this->helloRetailService->exportLogger(
                    HelretHelloRetail::EXPORT_ERROR,
                    [
                        'error' => $e->getMessage(),
                        'errorTrace' => $e->getTraceAsString(),
                        'errorType' => get_class($e),
                        'salesChannelId' => $salesChannelId
                    ]
                );
            }
        }
    }

    private function getIntervalsInSeconds(?string $type, string $salesChannelId): array
    {
        /* returns intervals in seconds for order and product */
        $intervals = [];

        if ($type == null || !in_array($type, array_keys(HelretHelloRetail::CONFIG_FIELDS))) {
            return $intervals;
        }

        try {
            $fields = $this->getSettingsFromSystemConfig($type, HelretHelloRetail::CONFIG_FIELDS, $salesChannelId);
            /* get the fields from systemConfig now */
            foreach ($fields as $feedIntervalSettings) {
                $amount = (int)$feedIntervalSettings['amount'];
                array_push($intervals, $this->getTimeTilNextRun($amount));
            }
        } catch (\Exception $e) {
            $this->helloRetailService->exportLogger(
                HelretHelloRetail::EXPORT_ERROR,
                [
                    'error' => $e->getMessage(),
                    'errorTrace' => $e->getTraceAsString(),
                    'errorType' => get_class($e)
                ]
            );
        }
        return $intervals;
    }

    private function getSettingsFromSystemConfig(string $type, ?array $fields, $salesChannelId): array
    {
        $valueFields = [];
        if ($fields == null) {
            /* return default */
            return $valueFields;
        }
        /* get configFields from systemConfigService */
        $configFields = $this->configService->get(HelretHelloRetail::CONFIG_PATH, $salesChannelId);

        /* shake out the required fields */
        foreach ($fields[$type] as $settingsFieldKey) {
            if (isset($configFields[$settingsFieldKey])) {
                $valueFields[] = [
                    "amount" => $configFields[$settingsFieldKey]
                ];
            }
        }

        return $valueFields;
    }

    private function getFeedsThatShouldRunNow(string $salesChannelId): array
    {
        $feeds = [];
        foreach (array_keys(HelretHelloRetail::CONFIG_FIELDS) as $feedName) {
            $intervals = $this->getIntervalsInSeconds($feedName, $salesChannelId);

            /* if intervals not an empty array, then get closest run */
            if (!empty($intervals)) {
                $nextRun = min($intervals);
            } else {
                /* else make sure it wont run, by setting a value above the run buffer */
                $nextRun = HelloRetailTask::getDefaultInterval() + 1;
            }

            /* if within the interval of this task, its time to run */
            if ($nextRun <= HelloRetailTask::getDefaultInterval()) {
                array_push($feeds, $feedName);
            }
        }

        return $feeds;
    }

    private function getTimeTilNextRun(int $interval): int
    {
        /* return seconds until next run */
        if ($interval != 0) {
            return abs((time() % $interval) - $interval);
        }

        /* if interval is 0, then never run it! */
        return HelloRetailTask::getDefaultInterval() + 1;
    }
}
