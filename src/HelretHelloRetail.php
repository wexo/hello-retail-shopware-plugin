<?php declare(strict_types=1);

namespace Helret\HelloRetail;

use Helret\HelloRetail\Service\ExportService;
use League\Flysystem\FilesystemException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class HelretHelloRetail extends Plugin
{
    public const LOG_CHANNEL = 'hello-retail';
    public const EXPORT_ERROR = 'hello-retail.export.error';
    public const EXPORT_SUCCESS = 'hello-retail.export.success';
    public const SALES_CHANNEL_TYPE_HELLO_RETAIL = '44f7e183909376bb5824abf830f4b879';
    public const FILE_TYPE_INDICATOR_SEPARATOR = '_';
    public const CONFIG_PATH = 'HelretHelloRetail.config';
    public const STORAGE_PATH = 'hello-retail';
    public const ORDER_FEED = "orders.xml";
    public const PRODUCT_FEED = "products.xml";
    public const CATEGORY_FEED = "categories.xml";

    /* Settings config, used in task handler for mapping systemConfig, for the run interval. */
    public const CONFIG_FIELDS = [
        "order" => [
            "amount" => "OrdersTimeAmount"
        ],
        "product" => [
            "amount" => "ProductTimeAmount"
        ],
        "category" => [
            "amount" => "CategoryTimeAmount"
        ]
    ];

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $salesChannelRepository = $this->container->get('sales_channel.repository');

        $ids = $salesChannelRepository->searchIds(
            ExportService::getSalesChannelCriteria(), // Get active salesChannels (HR)
            $deactivateContext->getContext()
        )->getIds();

        if ($ids) {
            $salesChannelRepository->update(
                array_map(function (string $salesChannelId) {
                    return [
                        'id' => $salesChannelId,
                        'active' => false
                    ];
                }, $ids),
                $deactivateContext->getContext()
            );
        }
    }

    /**
     * @throws FilesystemException
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $fs = $this->container->get('shopware.filesystem.public');
        if ($fs->directoryExists(self::STORAGE_PATH)) {
            $fs->deleteDirectory(self::STORAGE_PATH);
        }

        /** @var EntityRepository $salesChannelRepository */
        $salesChannelRepository = $this->container->get('sales_channel_type.repository');

        $ids = $salesChannelRepository->searchIds(
            (new Criteria())
                ->addFilter(new EqualsFilter('id', self::SALES_CHANNEL_TYPE_HELLO_RETAIL)),
            $uninstallContext->getContext()
        )->getIds();

        if ($ids) {
            $salesChannelRepository->delete(
                array_map(function ($id) {
                    return ['id' => $id];
                }, $ids),
                $uninstallContext->getContext()
            );
        }
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
        parent::postUpdate($updateContext);


        $updates = [];
        if (version_compare($updateContext->getUpdatePluginVersion(), "3.0.0", ">=")) {
            $updates[] = $this->getUpdateTemplatesV300();
        }
        if (version_compare($updateContext->getUpdatePluginVersion(), "4.4.1", ">=")) {
            $updates[] = $this->getUpdateTemplatesV441();
        }

        $versionUpdate = $updates && $this->updateTemplates($updates, $updateContext->getContext());

        // Trigger notification.
        if ($versionUpdate) {
            $this->container->get('notification.repository')->create([
                [
                    'status' => 'info',
                    'message' => 'Hello Retail feeds updated please verify updates/inheritance',
                    'requiredPrivileges' => [],
                    'adminOnly' => true,
                ]
            ], $updateContext->getContext());
        }

        // Mark "active" templates as inherited
        $template = [];
        $exportService = $this->container->get(ExportService::class);
        foreach ($exportService->getFeeds() as $feed) {
            $template[$feed->getFeed()] = [
                'headerTemplate' => $feed->getHeaderTemplate(),
                'bodyTemplate' => $feed->getBodyTemplate(),
                'footerTemplate' => $feed->getFooterTemplate(),
            ];
        }
        if ($template) {
            $this->updateTemplates([$template], $updateContext->getContext());
        }
    }

    private function getUpdateTemplatesV300(): array
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        return [
            "product" => [
                "headerTemplate" => '<?xml version="1.0" ?>
<products totalProductsCount="{{ productsTotal }}" updatedAt="{{ updatedAt }}">
',
                "bodyTemplate" => "<product>
    <url>{{ seoUrl('frontend.detail.page', {'productId': product.id}) }}</url>
    <title>{% if product.name %}{{ product.name }}{% else %}{{ product.translated.name }}{% endif %}</title>
    <ean>{{ product.ean }}</ean>
    <sku>{{ product.productNumber }}</sku>
    {% if product.cover %}
        {% set thumbnail = product.cover.media.thumbnails.elements|filter(img => img.width == 400)|first %}
        <imgurl>{% if thumbnail %}{{ thumbnail.url }}{% else %}{{ product.cover.media.url }}{% endif %}</imgurl>
    {% else %}
        <imgurl/>
    {% endif %}
    {% if product.price.extensions is not empty %}
        {% if product.price.extensions.calculatedPrice %}
            {% set price = product.price.extensions.calculatedPrice %}
        {% endif %}
        {% if product.price.extensions is not empty %}
            {% if product.price.extensions.calculatedPrices %}
                {% if product.price.extensions.calculatedPrices.count > 0 %}
                    {% set price = product.price.extensions.calculatedPrices.first %}
                {% endif %}
            {% endif %}
        {% endif %}
        <price>{{ price.unitPrice }}</price>
    {% else %}
        {% set prices = product.prices.elements|first %}
        <price>{% if prices %}{{ prices.price.elements|first.gross }}{% else %}{{ product.price.elements|first.gross }}{% endif %}</price>
    {% endif %}
    {% if product.cheapestPrice is not empty %}
        <oldPrice>{% if product.cheapestPrice %}{% if product.cheapestPrice.price.elements|first.listPrice is not null %}{{ product.cheapestPrice.price.elements|first.listPrice.gross|round(2, 'ceil') }}{% else %}{{ product.cheapestPrice.price.elements|first.gross }}{% endif %}{% else %}{{ product.price.elements|first.gross }}{% endif %}</oldPrice>
    {% else %}
        <oldPrice>{% if product.price.elements|first.listPrice %}{{ product.price.elements|first.listPrice.gross }}{% endif %}</oldPrice>
    {% endif %}
    <productnumber>{{ product.productNumber }}</productnumber>
    <hierarchies>
        {% if product.categories %}
            {% for category in product.categories.elements %}
                <hierarchy>
                    {% for breadCrumb in category.getBreadCrumb %}
                        <category>{{ breadCrumb }}</category>
                    {% endfor %}
                </hierarchy>
            {% endfor %}
        {% endif %}
    </hierarchies>

    {% if product.extensions.properties %}
        <properties>
            {% for key, value in product.extensions.properties %}
                <property>
                    <name>{{ key }}</name>
                    <options>
                        {% for option in value %}
                            <option>{{ option.name }}</option>
                        {% endfor %}
                    </options>
                </property>
            {% endfor %}
        </properties>
    {% endif %}

    <keywords>{% if product.searchKeywords %}{% for keyword in product.searchKeywords.elements %}{{ keyword.keyword }},{% endfor %}{% endif %}</keywords>
    {% set color = product.properties.elements|filter(property => property.group.name == 'Color')|first %}
    <color>{% if color %}{{ color.name }}{% endif %}</color>
    {% set gender = product.properties.elements|filter(property => property.group.name == 'Gender')|first %}
    <gender>{% if gender %}{{ gender.name }}{% endif %}</gender>
    <instock>{% if product.availableStock > 0 %}true{% else %}false{% endif %}</instock>
    <description>{{ product.description }}</description>
    <brand>{% if product.manufacturer %}{{ product.manufacturer.name }}{% endif %}</brand>
</product>
",
                "footerTemplate" => '</products>
',
            ],
            "category" => [
                "headerTemplate" => '<?xml version="1.0" ?>
<categories totalCategoriesCount="{{ categorysTotal }}" updatedAt="{{ updatedAt }}">
',
                "bodyTemplate" => "<category>
    <title>{% if category.name %}{{ category.name }}{% else %}{{ category.translated.name }}{% endif %}</title>
    <url>{{ seoUrl('frontend.navigation.page', {'navigationId': category.id}) }}</url>
    <description>{% if category.description %}{{ category.description }}{% else %}{{ category.translated.description }}{% endif %}</description>
    <keywords>{% if category.translated.keywords %}{% for keyword in category.translated.keywords %}{{ keyword.keyword }},{% endfor %}{% endif %}</keywords>
    <hierarchy>
        {% for breadCrumb in category.getBreadCrumb %}
            <category>{{ breadCrumb }}</category>
        {% endfor %}
    </hierarchy>
    {% if products is not empty %}
        <categoryProducts>
            {% for product in products %}
                {% if product and product.productNumber is defined %}
                    <product>
                        <url>{{ seoUrl('frontend.detail.page', {'productId': product.id}) }}</url>
                        <productNumber>{{ product.productNumber }}</productNumber>
                        <title>{% if product.name %}{{ product.name }}{% else %}{{ product.translated.name }}{% endif %}</title>
                    </product>
                {% endif %}
            {% endfor %}
        </categoryProducts>
    {% endif %}
</category>
",
                "footerTemplate" => '</categories>
',
            ],
            "order" => [
                "headerTemplate" => '<?xml version="1.0" ?>
<orders totalOrdersCount="{{ ordersTotal }}"  updatedAt="{{ updatedAt }}">
',
                "bodyTemplate" => "<order>
    <id>{{ order.id }}</id>
    <orderNumber>{{ order.orderNumber }}</orderNumber>
    <paymentStatus>
        {% if order.transactions.last.stateMachineState.translated is defined %}
            {{ order.transactions.last.stateMachineState.translated.name }}
        {% endif %}
    </paymentStatus>
    <deliveryStatus>
        {% if order.deliveries.first.stateMachineState.translated is defined %}
            {{ order.deliveries.first.stateMachineState.translated.name }}
        {% endif %}
    </deliveryStatus>
    <orderStatus>
        {% if order.stateMachineState.translated is defined %}
            {{ order.stateMachineState.translated.name }}
        {% endif %}
    </orderStatus>
    {% if order.lineItems %}
        <orderProducts>
            {% for lineItem in order.lineItems.elements %}
                {% if lineItem.product and lineItem.payload.productNumber is defined %}
                    <product>
                        <id>{{ lineItem.productId }}</id>
                        <url>{{ seoUrl('frontend.detail.page', {'productId': lineItem.productId}) }}</url>
                        <productNumber>{{ lineItem.payload.productNumber }}</productNumber>
                        <quantity>{{ lineItem.quantity }}</quantity>
                        <price>{{ lineItem.unitPrice }}</price>
                        {% if lineItem.price.calculatedTaxes is not empty %}
                            <priceNoTax>{{ lineItem.unitPrice - lineItem.price.calculatedTaxes.first.tax }}</priceNoTax>
                        {% endif %}
                    </product>
                {% endif %}
            {% endfor %}
        </orderProducts>
    {% endif %}
    <shippingPrice>{{ order.shippingTotal }}</shippingPrice>
    <createdDate>{{ order.orderDateTime|date('Y-m-d H:i:s') }}</createdDate>
    <CreatedDateWithoutTime>{{ order.orderDateTime|date('Y-m-d') }}</CreatedDateWithoutTime>
    <total>{{ order.amountTotal }}</total>
    <email>{{ order.orderCustomer.email }}</email>
    <paymentMethod>
        {% if order.transactions.last.paymentMethod.translated is defined %}
            {{ order.transactions.last.paymentMethod.translated.name }}
        {%  endif %}
    </paymentMethod>
    <shippingMethod>
        {% if order.deliveries.first.shippingMethod.translated is defined %}
            {{ order.deliveries.first.shippingMethod.translated.name }}
        {%  endif %}
    </shippingMethod>
</order>
",
                "footerTemplate" => '</orders>
',
            ],
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    private function getUpdateTemplatesV441(): array
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        return [
            'product' => [
                'bodyTemplate' => "<product>
    <url>{{ seoUrl('frontend.detail.page', {'productId': product.id}) }}</url>
    <title>{% if product.name %}{{ product.name }}{% else %}{{ product.translated.name }}{% endif %}</title>
    <ean>{{ product.ean }}</ean>
    <id>{{ product.id }}</id>
    <sku>{{ product.productNumber }}</sku>
    {% if product.cover %}
        {% set thumbnail = product.cover.media.thumbnails.elements|filter(img => img.width == 400)|first %}
        <imgurl>{% if thumbnail %}{{ thumbnail.url }}{% else %}{{ product.cover.media.url }}{% endif %}</imgurl>
    {% else %}
        <imgurl/>
    {% endif %}

    {# Price #}
    {% set price = product.calculatedPrices.count > 0 ? product.calculatedPrices.last : product.calculatedPrice %}
    {% set listPrice = price.listPrice %}
    <price>{{ price.unitPrice }}</price>
    <oldPrice>{% if listPrice %}{{ listPrice.price }}{% endif %}</oldPrice>
    <displayFrom>{{ product.calculatedPrices.count > 1 }}</displayFrom>
    {# Price end #}


    <description>{{ product.description }}</description>
    <brand>{% if product.manufacturer %}{{ product.manufacturer.name }}{% endif %}</brand>

    <productnumber>{{ product.productNumber }}</productnumber>
    <hierarchies>
        {% if product.categories %}
            {% for category in product.categories.elements %}
                <hierarchy>
                    {%- for breadCrumb in category.getBreadcrumb -%}
                        <category>{{ breadCrumb }}</category>
                    {%- endfor -%}
                </hierarchy>
            {% endfor %}
        {% endif %}
    </hierarchies>

    {% if product.extensions.properties %}
        <properties>
            {% for key, value in product.extensions.properties.all() %}
                <property>
                    <name>{{ key }}</name>
                    <options>
                        {%- for option in value -%}
                            <option>{{ option.name }}</option>
                        {%- endfor -%}
                    </options>
                </property>
            {% endfor %}
        </properties>
    {% endif %}

    <keywords>{% if product.searchKeywords %}{% for keyword in product.searchKeywords.elements %}{{ keyword.keyword }},{% endfor %}{% endif %}</keywords>

    {% set color = product.properties.elements|filter(property => property.group.name == 'Color')|first %}
    <color>{% if color %}{{ color.name }}{% endif %}</color>

    {% set gender = product.properties.elements|filter(property => property.group.name == 'Gender')|first %}
    <gender>{% if gender %}{{ gender.name }}{% endif %}</gender>
    <instock>{% if product.availableStock > 0 %}true{% else %}false{% endif %}</instock>
</product>
",
            ],
            'category' => [
                'bodyTemplate' => "<category>
    <title>{% if category.name %}{{ category.name }}{% else %}{{ category.translated.name }}{% endif %}</title>
    <url>{{ seoUrl('frontend.navigation.page', {'navigationId': category.id}) }}</url>
    <description>{% if category.description %}{{ category.description }}{% else %}{{ category.translated.description }}{% endif %}</description>
    <keywords>{% if category.translated.keywords %}{% for keyword in category.translated.keywords %}{{ keyword.keyword }},{% endfor %}{% endif %}</keywords>

    <hierarchy>
        {% for breadCrumb in category.getBreadCrumb %}
            <category>{{ breadCrumb }}</category>
        {% endfor %}
    </hierarchy>

    {% if products is defined and products is not empty %}
        <categoryProducts>
            {% for product in products %}
                {%- if product and product.productNumber is defined -%}
                    <product>
                        <url>{{ seoUrl('frontend.detail.page', {'productId': product.id}) }}</url>
                        <productNumber>{{ product.productNumber }}</productNumber>
                        <title>{% if product.name %}{{ product.name }}{% else %}{{ product.translated.name }}{% endif %}</title>
                    </product>
                {%- endif -%}
            {% endfor %}
        </categoryProducts>
    {% endif %}
</category>
"
            ]
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong
    }


    private function updateTemplates(array $updateTemplates, Context $context): bool
    {
        /** @var EntityRepository<SalesChannelCollection> $salesChannelRepository */
        $salesChannelRepository = $this->container->get('sales_channel.repository');
        $salesChannels = $salesChannelRepository->search(
            (new Criteria())
                ->addFilter(new EqualsFilter('typeId', self::SALES_CHANNEL_TYPE_HELLO_RETAIL)),
            $context
        );

        if (!$salesChannels->first()) {
            return false;
        }


        $updates = [];
        foreach ($updateTemplates as $updateTemplate) {
            if (!$updateTemplate) {
                continue;
            }

            /** @var SalesChannelEntity $salesChannel */
            foreach ($salesChannels as $salesChannel) {
                $updated = false;
                $configuration = $salesChannel->getConfiguration();
                if (!isset($configuration['feeds']) || !is_array($configuration['feeds'])) {
                    continue;
                }

                foreach ($configuration['feeds'] as $key => $feed) {
                    if (!isset($updateTemplate[$key])) {
                        continue;
                    }

                    $template = $updateTemplate[$key];
                    foreach (['headerTemplate', 'bodyTemplate', 'footerTemplate'] as $templateKey) {
                        if (!isset($template[$templateKey])) {
                            continue;
                        }

                        if (!$configuration['feeds'][$key][$templateKey]) {
                            // Considered inherited
                            continue;
                        }

                        $entity = preg_replace('/\s+/', '', trim($template[$templateKey]));
                        $config = preg_replace('/\s+/', '', trim($configuration['feeds'][$key][$templateKey]));

                        if ($entity === $config) {
                            $configuration['feeds'][$key][$templateKey] = null;
                            $updated = true;
                        }
                    }
                }

                if ($updated) {
                    $salesChannel->setConfiguration($configuration);
                    $updates[$salesChannel->getId()] = [
                        'id' => $salesChannel->getId(),
                        'configuration' => $configuration
                    ];
                }
            }
        }

        if ($updates) {
            $salesChannelRepository->update(array_values($updates), $context);
            return true;
        }

        return false;
    }
}
