<?php declare(strict_types=1);

namespace Helret\HelloRetail\Component\MessageQueue;

use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;
use League\Flysystem\FileNotFoundException;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\ProductStream\Service\ProductStreamBuilderInterface;
use Shopware\Core\Framework\Adapter\Translation\Translator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\Handler\AbstractMessageHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Helret\HelloRetail\Export\ExportEntityElement;
use Helret\HelloRetail\Export\TemplateType;
use Helret\HelloRetail\Service\HelloRetailService;
use Helret\HelloRetail\HelretHelloRetail;

/**
 * Class HelloRetailExportHandler
 * @package Helret\HelloRetail\Component\MessageQueue
 */
class HelloRetailExportHandler extends AbstractMessageHandler
{
    // TODO: Should be settings
    private const RETRIES = 20;
    private const SLEEP_BETWEEN_RETRIES = 20; // Seconds

    protected LoggerInterface $logger;
    protected ContainerInterface $container;
    protected Translator $translator;
    protected Filesystem $filesystem;
    protected HelloRetailService $helloRetailService;
    protected MessageBusInterface $bus;
    private ProductStreamBuilderInterface $productStreamBuilder;

    /**
     * HelloRetailExportHandler constructor.
     * @param LoggerInterface $logger
     * @param ContainerInterface $container
     * @param Translator $translator
     * @param HelloRetailService $helloRetailService
     * @param MessageBusInterface $bus
     */
    public function __construct(
        LoggerInterface $logger,
        ContainerInterface $container,
        Translator $translator,
        HelloRetailService $helloRetailService,
        MessageBusInterface $bus,
        ProductStreamBuilderInterface $productStreamBuilder
    ) {
        $this->logger = $logger;
        $this->container = $container;
        $this->translator = $translator;
        $this->helloRetailService = $helloRetailService;
        $this->bus = $bus;

        $fullPath = $helloRetailService->getFeedDirectoryPath();
        $localFilesystemAdapter = new Local($fullPath);
        $this->filesystem = new Filesystem($localFilesystemAdapter);

        $this->productStreamBuilder = $productStreamBuilder;
    }

    /**
     * @return iterable
     */
    public static function getHandledMessages(): iterable
    {
        return [ExportEntityElement::class];
    }

    /**
     * @param ExportEntityElement $message
     */
    public function handle($message): void
    {
        if ($message->getTemplateType() === TemplateType::FOOTER) {
            $this->collectFiles($message);
            return;
        }
        $feedEntity = $message->getFeedEntity();
        $feed = $feedEntity->getFeed();
        $salesChannelContext = $message->getSalesChannelContext();

        $context = $salesChannelContext->getContext();
        $context->setConsiderInheritance(true);


        $this->translator->injectSettings(
            $salesChannelContext->getSalesChannel()->getId(),
            $feedEntity->getDomain()->getLanguageId(),
            $feedEntity->getDomain()->getLanguage()->getLocaleId(),
            $context
        );

        /** @var EntityRepositoryInterface $repository */
        $repository = $this->container->get("$feed.repository");

        $criteria = new Criteria([$message->getId()]);
        foreach ($feedEntity->getAssociations() as $association) {
            $criteria->addAssociation($association);
        }

        $entity = $repository->search($criteria, $context)->first();

        try {
            if($feed == 'product' && $entity->getProperties()) {
                /** @var ProductEntity $entity */
                $properties = [];
                foreach ($entity->getProperties() as $property) {
                    $properties[$property->getGroup()->getName()][] = $property;
                }
                $entity->setExtensions(['properties' => $properties]);
            }
            $data = [
                "{$feed}" => $entity
            ];

            if ($feed === 'category') {
                if ($entity->getProductStreamId()) {
                    $filters = $this->productStreamBuilder->buildFilters(
                        $entity->getProductStreamId(),
                        $context
                    );

                    $productCriteria = new Criteria();
                    $productCriteria->addFilter(...$filters);

                    $productRepository = $this->container->get("product.repository");
                    $products = $productRepository->search($productCriteria, $context);

                    $data['products'] = $products;
                } else {
                    $data['products'] = $entity->getProducts();
                }
            }


            $output = $this->helloRetailService->renderBody(
                $feedEntity,
                $salesChannelContext,
                $data
            );
            if (!$output) {
                $this->translator->resetInjection();
                $this->helloRetailService->exportLogger(
                    HelretHelloRetail::EXPORT_ERROR,
                    [
                        'entityId' => $entity->getId(),
                        'feed' => $feed,
                        'entityType' => $message->getEntityType(),
                        'templateType' => $message->getTemplateType()
                    ]
                );
                return;
            }
            $this->filesystem->put($message->getDirectory() . DIRECTORY_SEPARATOR . $message->getId(), $output);
            $this->translator->resetInjection();
        } catch (\Error | \TypeError | \Exception $e) {
            $this->helloRetailService->exportLogger(
                HelretHelloRetail::EXPORT_ERROR,
                [
                    'entityId' => $entity->getId(),
                    'feed' => $feed,
                    'entityType' => $message->getEntityType(),
                    'templateType' => $message->getTemplateType(),
                    'error' => $e->getMessage(),
                    'errorTrace' => $e->getTraceAsString(),
                    'errorType' => get_class($e)
                ]
            );
        }
    }

    /**
     * @param ExportEntityElement $message
     */
    private function collectFiles(ExportEntityElement $message)
    {
        $dir = $message->getDirectory();

        $files = [];
        foreach ($this->filesystem->listContents($dir) as $file) {
            $filename = $file['filename'] ?? null;
            if ($filename == TemplateType::HEADER) {
                // Insert at the beginning of the array
                array_unshift($files, $filename);
                continue;
            }
            $files[] = $filename;
        }

        $allIds = $message->getAllIds();
        $successes = array_intersect($allIds, $files);
        $failures = 0;
        // TODO: Threshold should be a setting
        $successThreshold = floor((count($allIds) * 0.90));

        if (count($successes) >= $successThreshold) {
            $feedContent = "";
            try {
                $header = $dir . DIRECTORY_SEPARATOR . array_splice($files, 0, 1)[0];
                $feedContent .= $this->filesystem->read($header);
            } catch (\Error | \TypeError | FileNotFoundException | \Exception $e) {
                $this->helloRetailService->exportLogger(
                    HelretHelloRetail::EXPORT_ERROR,
                    [
                        'header' => $header ?? null,
                        'feed' => $message->getFeedEntity()->getFeed(),
                        'entityType' => $message->getEntityType(),
                        'templateType' => $message->getTemplateType(),
                        'error' => $e->getMessage(),
                        'errorTrace' => $e->getTraceAsString(),
                        'errorType' => get_class($e)
                    ]
                );
                return;
            }
            foreach ($files as $file) {
                try {
                    $feedContent .= $this->filesystem->read($dir . DIRECTORY_SEPARATOR . $file);
                } catch (FileNotFoundException $e) {
                    $failures++;
                    continue;
                }
            }

            $feedEntity = $message->getFeedEntity();

            // Construct file
            $feedContent .= $this->helloRetailService->renderFooter(
                $feedEntity,
                $message->getSalesChannelContext()
            );

            if ($failures > (count($allIds) - $successThreshold)) {
                $this->handleRetry(
                    $message,
                    $failures,
                    $successThreshold ?? null,
                    $allIds,
                    $dir
                );

                return;
            } else {
                $this->filesystem->put(
                    $feedEntity->getFeedDirectory() . DIRECTORY_SEPARATOR . $feedEntity->getFile(),
                    $feedContent
                );
            }
        } else {
            $this->handleRetry(
                $message,
                $failures,
                $successThreshold  ?? null,
                $allIds,
                $dir
            );

            return;
        }

        $this->helloRetailService->exportLogger(
            HelretHelloRetail::EXPORT_SUCCESS,
            [
                'feed' => $message->getFeedEntity()->getFeed()
            ],
            Logger::INFO
        );

        $this->filesystem->deleteDir($dir);
    }

    /**
     * @param ExportEntityElement $message
     * @param int $failures
     * @param float|null $successThreshold
     * @param array|null $allIds
     * @param string $dir
     */
    private function handleRetry(
        ExportEntityElement $message,
        int $failures,
        ?float $successThreshold,
        ?array $allIds,
        string $dir
    ) {
        $retryCount = $message->getRetryCount();
        if ($retryCount < self::RETRIES) {
            sleep(self::SLEEP_BETWEEN_RETRIES);
            $message->setRetryCount($retryCount + 1);
            $this->bus->dispatch(new Envelope($message));
        } else {
            $this->helloRetailService->exportLogger(
                HelretHelloRetail::EXPORT_ERROR,
                [
                    'retryCount' => $retryCount,
                    'failures' => $failures,
                    'allIdsCount' => count($allIds),
                    'successThreshold' => $successThreshold,
                    'feed' => $message->getFeedEntity()->getFeed(),
                    'entityType' => $message->getEntityType(),
                    'templateType' => $message->getTemplateType()
                ]
            );

            $this->filesystem->deleteDir($dir);
        }
    }
}