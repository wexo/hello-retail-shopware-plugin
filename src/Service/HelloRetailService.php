<?php

declare(strict_types=1);

namespace Helret\HelloRetail\Service;

use DateTime;
use Error;
use Exception;
use Helret\HelloRetail\Core\Content\Feeds\ExportEntity;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Shopware\Core\Framework\Adapter\Twig\TwigVariableParser;
use Shopware\Core\Framework\Adapter\Twig\TwigVariableParserFactory;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Log\LogEntryCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Twig\Environment;
use TypeError;
use League\Flysystem\Filesystem;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\Adapter\Twig\Exception\StringTemplateRenderingException;
use Shopware\Core\Framework\Adapter\Twig\StringTemplateRenderer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Helret\HelloRetail\Export\EntityType;
use Helret\HelloRetail\Export\ExportEntityElement;
use Helret\HelloRetail\Export\ExportEntityInterface;
use Helret\HelloRetail\Export\FeedEntity;
use Helret\HelloRetail\Export\FeedEntityInterface;
use Helret\HelloRetail\Export\TemplateType;
use Helret\HelloRetail\HelretHelloRetail;

class HelloRetailService
{
    protected Filesystem $filesystem;
    protected TwigVariableParser $twigVariableParser;

    /**
     * @param EntityRepository<LogEntryCollection> $logEntryRepository
     * @param EntityRepository<SalesChannelDomainCollection> $salesChannelDomainRepository
     */
    public function __construct(
        protected EntityRepository $logEntryRepository,
        protected LoggerInterface $logger,
        protected MessageBusInterface $bus,
        protected StringTemplateRenderer $templateRenderer,
        protected ContainerInterface $container,
        protected SalesChannelContextServiceInterface $salesChannelContextService,
        protected SeoUrlPlaceholderHandlerInterface $seoUrlPlaceholderHandler,
        protected SerializerInterface $serializer,
        protected EntityRepository $salesChannelDomainRepository,
        protected SystemConfigService $configService,
        protected string $projectRoot,
        protected ExportService $exportService,
        Environment $twig,
        TwigVariableParserFactory $parserFactory
    ) {
        $fullPath = $this->getFeedDirectoryPath();
        $localFilesystemAdapter = new LocalFilesystemAdapter($fullPath);
        $this->filesystem = new Filesystem($localFilesystemAdapter);
        $this->twigVariableParser = $parserFactory->getParser($twig);
    }

    public function getFeedDirectoryPath(): string
    {
        $filesDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'files';
        return $filesDir . DIRECTORY_SEPARATOR . HelretHelloRetail::STORAGE_PATH;
    }

    /**
     * @throws Exception
     * @throws FilesystemException
     */
    public function export(ExportEntityInterface $exportEntity, string $feed): bool
    {
        $salesChannelDomainCriteria = new Criteria([$exportEntity->getSalesChannelDomainId()]);
        $salesChannelDomainCriteria->addAssociation('language');

        /** @var SalesChannelDomainEntity $salesChannelDomain */
        $salesChannelDomain = $this->salesChannelDomainRepository
            ->search($salesChannelDomainCriteria, Context::createDefaultContext())->first();

        /**
         * No token needed since we haven't generated one with any settings.
         * Implement when we need to pass currency.
         * @see ProductExportPartialGenerationHandler::finalizeExport
         */
        $salesChannelContext = $this->salesChannelContextService->get(
            new SalesChannelContextServiceParameters(
                $exportEntity->getStorefrontSalesChannelId(),
                '',
                $salesChannelDomain->getLanguageId(),
                $salesChannelDomain->getCurrencyId(),
                $salesChannelDomain->getId()
            )
        );
        $context = $salesChannelContext->getContext();
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        /** @var FeedEntityInterface $feedEntity */
        if (isset($exportEntity->getFeeds()[$feed])) {
            try {
                $feedEntity = $this->serializer
                    ->deserialize(json_encode($exportEntity->getFeeds()[$feed]), FeedEntity::class, 'json');
            } catch (Error|TypeError|NotEncodableValueException|Exception $e) {
                $this->exportLogger(
                    HelretHelloRetail::EXPORT_ERROR,
                    [
                        'feed' => $feed,
                        'error' => $e->getMessage(),
                        'errorTrace' => $e->getTraceAsString(),
                        'errorType' => get_class($e)
                    ]
                );

                return false;
            }
        } else {
            $feedEntity = new FeedEntity();
        }

        $exportFeed = $this->exportService->getFeed($feed);

        $feedEntity->setFeedDirectory($exportEntity->getFeedDirectory());
        $feedEntity->setFeed($feed);
        $feedEntity->setSalesChannelDomainId($salesChannelDomain->getId());
        $feedEntity->setSalesChannelDomainLanguageId($salesChannelDomain->getLanguageId());
        $feedEntity->setSalesChannelDomainCurrencyId($salesChannelDomain->getCurrencyId());
        $feedEntity->setSalesChannelDomainLanguageLocaleId($salesChannelDomain->getLanguage()->getLocaleId());
        $feedEntity->setSalesChannelId($salesChannelDomain->getSalesChannelId());
        $feedEntity->setSalesChannelDomainUrl($salesChannelDomain->getUrl());
        $feedEntity->setEntity($exportFeed->getEntity());
        $feedEntity->setFile($exportFeed->getFile());

        $this->setInheritedTemplates($feedEntity, $exportFeed);
        if (!$feedEntity->getHeaderTemplate() || !$feedEntity->getBodyTemplate() || !$feedEntity->getFooterTemplate()) {
            return false;
        }

        $criteria = new Criteria();
        if (EntityType::getMatchingEntityType($feed) == EntityType::PRODUCT) {
            $criteria->addFilter(new EqualsFilter('product.active', true));
            $criteria->addFilter(
                new EqualsFilter(
                    'product.visibilities.salesChannelId',
                    $salesChannelId
                )
            );
        } elseif (EntityType::getMatchingEntityType($feed) == EntityType::CATEGORY) {
            $categoryIds = [
                $salesChannelContext->getSalesChannel()->getNavigationCategoryId(),
            ];

            if ($salesChannelContext->getSalesChannel()->getServiceCategoryId()) {
                $categoryIds[] = $salesChannelContext->getSalesChannel()->getServiceCategoryId();
            }
            if ($salesChannelContext->getSalesChannel()->getFooterCategoryId()) {
                $categoryIds[] = $salesChannelContext->getSalesChannel()->getFooterCategoryId();
            }

            /**
             * Categories by salesChannel category ids.
             * @see CategoryBreadcrumbBuilder::getSalesChannelFilter
             */
            $criteria->addFilter(
                new OrFilter(
                    array_map(
                        fn(string $id) => new ContainsFilter('path', '|' . $id . '|'),
                        $categoryIds
                    )
                )
            );
            $criteria->addFilter(new EqualsFilter('category.active', true));
        } elseif (EntityType::getMatchingEntityType($feed) == EntityType::ORDER) {
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
            if ($this->configService->get('HelretHelloRetail.config.orderLimit', $salesChannelId)) {
                $amountOfMonths = $this->configService->getInt(
                    'HelretHelloRetail.config.orderLimitMonths',
                    $salesChannelId
                ) ?: 2;
                $criteria->addFilter(
                    new RangeFilter(
                        'createdAt',
                        [RangeFilter::GTE => (new DateTime("-{$amountOfMonths} month"))->format('Y-m-d')]
                    )
                );
            }
        }

        $this->extendCriteria($criteria, $feed, $feedEntity, $salesChannelContext);

        $repository = $this->container->get(("{$exportFeed->getEntity()}.repository"));
        if ($repository instanceof SalesChannelRepository) {
            $entityIdsResult = $repository->searchIds($criteria, $salesChannelContext);
            /** @var EntityRepository $pureRepo */
            $pureRepo = $this->container->get(("$feed.repository"));
            $associations = $this->getAssociations($feedEntity->getBodyTemplate(), $pureRepo);
            unset($pureRepo);
        } else {
            /** @var EntityRepository $repository */
            $entityIdsResult = $repository->searchIds($criteria, $context);
            $associations = $this->getAssociations($feedEntity->getBodyTemplate(), $repository);
        }

        // Dynamically add associations
        $feedEntity->setAssociations(
            array_merge(
                $feedEntity->getAssociations(),
                $exportFeed->associations,
                $associations
            )
        );

        $entityIds = $entityIdsResult->getIds();

        $content = $this->renderHeader($feedEntity, $context, [
            "{$feed}sTotal" => $entityIdsResult->getTotal(),
            "total" => $entityIdsResult->getTotal(),
            "updatedAt" => date("Y-m-d H:i:s")
        ]);
        if (!$content) {
            // If the header render failed, no need to continue.
            // The error will already have been logged in the renderTemplate function
            return false;
        }

        // Create temp dir for all file parts: {dir}/{salesChannelId}_{entityType}
        // Change: Use same dir (salesChannelId) to ensure lots of folders aren't created in case of failure / staling
        $tmpDir = 'hello-retail-generation-content/'
            . $salesChannelId
            . HelretHelloRetail::FILE_TYPE_INDICATOR_SEPARATOR
            . $feed;

        $this->filesystem->write($tmpDir . DIRECTORY_SEPARATOR . TemplateType::HEADER, $content);

        $config = $this->configService->get("HelretHelloRetail.config", $salesChannelId);

        foreach ($entityIds as $entityId) {
            $message = new ExportEntityElement(
                $tmpDir,
                $entityId,
                $feedEntity,
                EntityType::getMatchingEntityType($feed),
                TemplateType::BODY
            );
            $message->setExportConfig($config);

            $this->bus->dispatch($message);
        }

        $footerElement = new ExportEntityElement(
            $tmpDir,
            TemplateType::FOOTER,
            $feedEntity,
            EntityType::getMatchingEntityType($feed),
            TemplateType::FOOTER
        );

        $footerElement->setExportConfig($config);
        $footerElement->setAllIds($entityIds);

        $this->bus->dispatch($footerElement);

        return true;
    }

    public function renderHeader(
        FeedEntityInterface $feedEntity,
        Context $context,
        array $data = []
    ): bool|string {
        return $this->renderTemplate($feedEntity->getHeaderTemplate(), $data, $context);
    }

    public function renderBody(
        FeedEntityInterface $feedEntity,
        SalesChannelContext $salesChannelContext,
        string $domainUrl,
        array $data = []
    ): string {
        return $this->replaceSeoUrlPlaceholder(
            $this->renderTemplate($feedEntity->getBodyTemplate(), $data, $salesChannelContext->getContext()),
            $domainUrl,
            $salesChannelContext
        );
    }

    public function renderFooter(
        FeedEntityInterface $feedEntity,
        Context $context,
        array $data = []
    ): bool|string {
        return $this->renderTemplate($feedEntity->getFooterTemplate(), $data, $context);
    }

    public function replaceSeoUrlPlaceholder(
        string $content,
        string $domainUrl,
        SalesChannelContext $salesChannelContext
    ): string {
        return $this->seoUrlPlaceholderHandler->replace($content, $domainUrl, $salesChannelContext);
    }

    public function exportLogger(
        string $event,
        array $context,
        int $level = Logger::ERROR
    ): void {
        $this->logEntryRepository->create(
            [
                [
                    'message' => $event,
                    'context' => $context,
                    'level' => $level,
                    'channel' => HelretHelloRetail::LOG_CHANNEL
                ]
            ],
            Context::createDefaultContext()
        );
    }

    private function renderTemplate(
        ?string $template,
        array $data,
        Context $context
    ): bool|string {
        try {
            return $this->templateRenderer->render($template, $data, $context) . PHP_EOL;
        } catch (Error|TypeError|Exception|StringTemplateRenderingException $e) {
            $this->exportLogger(
                HelretHelloRetail::EXPORT_ERROR,
                [
                    'template' => $template,
                    'error' => $e->getMessage(),
                    'errorTrace' => $e->getTraceAsString(),
                    'errorType' => get_class($e)
                ]
            );
        }
        return false;
    }

    protected function getAssociations(string $template, EntityRepository $repo): array
    {
        try {
            $variables = $this->twigVariableParser->parse($template);
        } catch (\Exception $e) {
            return [];
            // Should we throw, or just rely on the associations from the conf file?
            // throw new RenderProductException($e->getMessage());
        }

        $associations = [];
        foreach ($variables as $variable) {
            $associations[] = EntityDefinitionQueryHelper::getAssociationPath($variable, $repo->getDefinition());
        }

        return array_filter(array_unique($associations));
    }

    protected function setInheritedTemplates(FeedEntityInterface $feedEntity, ?ExportEntity $exportEntity): void
    {
        if ($feedEntity->getHeaderTemplate() && $feedEntity->getBodyTemplate() && $feedEntity->getFooterTemplate()) {
            return;
        }

        if (!$exportEntity) {
            return;
        }

        if (!$feedEntity->getHeaderTemplate()) {
            $feedEntity->setHeaderTemplate($exportEntity->getHeaderTemplate());
        }
        if (!$feedEntity->getBodyTemplate()) {
            $feedEntity->setBodyTemplate($exportEntity->getBodyTemplate());
        }
        if (!$feedEntity->getFooterTemplate()) {
            $feedEntity->setFooterTemplate($exportEntity->getFooterTemplate());
        }
    }

    protected function extendCriteria(
        Criteria $criteria,
        string $feed,
        FeedEntityInterface $feedEntity,
        SalesChannelContext $context
    ): void {
    }
}
