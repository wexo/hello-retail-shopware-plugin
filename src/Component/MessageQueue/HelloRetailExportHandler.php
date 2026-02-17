<?php declare(strict_types=1);

namespace Helret\HelloRetail\Component\MessageQueue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;
use League\Flysystem\FileNotFoundException;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\ProductStream\Service\ProductStreamBuilderInterface;
use Shopware\Core\Framework\Adapter\Translation\AbstractTranslator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\Handler\AbstractMessageHandler;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
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
    protected AbstractTranslator $translator;
    protected Filesystem $filesystem;
    protected HelloRetailService $helloRetailService;
    protected MessageBusInterface $bus;
    private ProductStreamBuilderInterface $productStreamBuilder;

    public function __construct(
        LoggerInterface $logger,
        ContainerInterface $container,
        AbstractTranslator $translator,
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
        $this->filesystem = new Filesystem(new Local($fullPath));

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
            /**
             * Ugly fix/hack
             * .... Occasionally the Hello Retail "footer"/ending message is rendered before all categories are rendered
             * The "sleep", seems to be enough when generating/ending feed
             */
            sleep(10);

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

        $criteria = new Criteria([$message->getId()]);
        foreach ($feedEntity->getAssociations() as $association) {
            $criteria->addAssociation($association);
        }


        /** @var SalesChannelProductEntity|CategoryEntity|OrderEntity $entity */
        $repository = $this->container->get(("{$feedEntity->getEntity()}.repository"));
        if ($repository instanceof SalesChannelRepositoryInterface) {
            // sales_channel.product throws an exception if the parent association is applied
            if ($criteria->hasAssociation("parent")) {
                $criteria->removeAssociation("parent");
            }

            // sales_channel.product.product isn't an association, otherwise a warning would be thrown every message.
            if ($criteria->hasAssociation("product")) {
                $criteria->removeAssociation("product");
            }

            $entity = $repository->search($criteria, $salesChannelContext)->first();
        } else {
            if ($feedEntity->getEntity() === CategoryDefinition::ENTITY_NAME) {
                // Remove products association for now.
                $criteria->removeAssociation("products");
                if ($message->getConfigValue("includeCategoryProducts")) {
                    // Check assignment type, if == product add products association (Seems to have best performance)

                    /** @var Connection $connection */
                    $connection = $this->container->get(Connection::class);
                    try {
                        $type = $connection->fetchOne("SELECT product_assignment_type FROM category WHERE id = :id", [
                            "id" => Uuid::fromHexToBytes($message->getId())
                        ]);
                    } catch (Exception $e) {
                        $type = "product";
                    }

                    // If the category assignment type is product_stream a stream will be loaded later on.
                    if ($type === "product") {
                        $criteria->addAssociation("products");
                    }
                }
            }

            $entity = $repository->search($criteria, $salesChannelContext->getContext())->first();
        }

        $data = [$feed => $entity];
        if ($feed === 'product') {
            // Backwards compatability
            if ($message->getConfigValue("advancedPricing")) {
                $entity->getPrice()->addExtensions([
                    'calculatedPrices' => $entity->getCalculatedPrices(),
                    'calculatedPrice' => $entity->getCalculatedPrice()
                ]);
            }

            if ($entity->getProperties()) {
                $properties = [];
                foreach ($entity->getProperties() as $property) {
                    $properties[$property->getGroup()->getName()][] = $property;
                }
                $entity->addExtension("properties", new ArrayStruct($properties));
            }
        } elseif ($feed === 'category' && $message->getConfigValue("includeCategoryProducts")) {
            if ($entity->getProductAssignmentType() === "product_stream" && $entity->getProductStreamId()) {
                $productRepository = $this->container->get("product.repository");
                $data['products'] = $productRepository->search(
                    (new Criteria())
                        ->addFilter(...$this->productStreamBuilder->buildFilters(
                            $entity->getProductStreamId(),
                            $context
                        ))
                        // Ensure product's on this salesChannel and is active
                        ->addFilter(new ProductAvailableFilter(
                            $salesChannelContext->getSalesChannelId(),
                            ProductVisibilityDefinition::VISIBILITY_LINK
                        )),
                    $context
                )->getEntities();
            } else {
                $data['products'] = $entity->getProducts();
            }
        }

        try {
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
        } catch (\Error|\TypeError|\Exception $e) {
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
        $this->translator->resetInjection();
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
            } catch (\Error|\TypeError|FileNotFoundException|\Exception $e) {
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
                $successThreshold ?? null,
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
