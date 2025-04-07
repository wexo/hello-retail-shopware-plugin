import template from './sw-sales-channel-detail-base.html.twig';
import './style.scss';

const {Component, Utils} = Shopware;
const {Criteria} = Shopware.Data;

Component.override('sw-sales-channel-detail-base', {
    template,

    inject: ['helloRetailService'],

    data() {
        return {
            isEntitiesLoading: false,
            storefrontDomainUrl: '',
            exportFeeds: null,
        };
    },

    computed: {
        isHelloRetail() {
            return this.salesChannel && this.salesChannel.typeId === this.helloRetailService.getTypeId();
        },

        // Page:
        getFeedList() {
            if (!this.exportFeeds) {
                return {};
            }

            return Object.keys(this.exportFeeds).sort((a, b) => a.localeCompare(b));
        },

        storefrontSalesChannelDomainCriteria() {
            if (!this.isHelloRetail) {
                return this.$super('storefrontSalesChannelDomainCriteria');
            }

            const criteria = new Criteria(1, 25);
            criteria.addFilter(Criteria.equals('salesChannelId', this.salesChannel.configuration.storefrontSalesChannelId));
            return criteria;
        },

        salesChannelCriteria() {
            const criteria = this.storefrontSalesChannelCriteria;
            const domainPart = criteria.getAssociation('domains');
            domainPart.setLimit(1); // Load first domain

            return criteria;
        }
    },

    created() {
        this.$emit('invalid-file-name', true);

        this.initHelloRetailConfiguration();

        Promise.all([
            this.getStorefrontDomain(),
            this.loadFeedEntities()
        ]).finally(() => this.$emit('valid-file-name', true));
    },

    methods: {
        // Override(s) for performance
        createCategoryCollections() {
            if (!this.isHelloRetail) {
                this.$super('createCategoryCollections');
            }

            // Ignore category requests, slightly faster admin. Saves 3x category requests
            // Categories aren't needed (HR Admin)
        },

        generateAuthToken() {
            this.salesChannel.configuration.authToken = 'tok_sw_' + Utils.createId();
        },

        // HR:
        initHelloRetailConfiguration() {
            // Ensure salesChannel.configuration isset.
            if (!this.salesChannel.configuration) {
                this.salesChannel.configuration = {};
            }

            if (!this.salesChannel.configuration.feedDirectory) {
                this.salesChannel.configuration.feedDirectory = Utils.createId();
            }

            if (!this.salesChannel.configuration.storefrontSalesChannelId) {
                this.salesChannel.configuration.storefrontSalesChannelId = null;
            }

            if (!this.salesChannel.configuration.salesChannelDomainId) {
                this.salesChannel.configuration.salesChannelDomainId = null;
            }

            if (!this.salesChannel.configuration.authToken) {
                this.salesChannel.configuration.authToken = 'tok_sw_' + Utils.createId();
            }
        },

        loadFeedEntities() {
            this.isEntitiesLoading = true;
            return this.helloRetailService.getExportEntities()
                .then(result => {
                    this.exportFeeds = result.feeds;
                })
                .then(() => {
                    let feeds = {};

                    if (this.exportFeeds && Object.keys(this.exportFeeds).length) {
                        Object.entries(this.exportFeeds).forEach(([key, feed]) => {
                            feeds[key] = {
                                file: feed.file,
                                name: feed.feed,
                                headerTemplate: null,
                                bodyTemplate: null,
                                footerTemplate: null
                            };
                        });
                    } else {
                        feeds = this.helloRetailTemplateService.getExportTemplateRegistry();
                        Object.keys(feeds).forEach(key => {
                            feeds[key].headerTemplate = null;
                            feeds[key].bodyTemplate = null;
                            feeds[key].footerTemplate = null;
                        });
                    }

                    if (!this.salesChannel.configuration.feeds) {
                        this.$set(this.salesChannel, 'configuration', {
                            ...this.salesChannel.configuration,
                            feeds: feeds
                        });
                    }
                })
                .finally(() => {
                    this.isEntitiesLoading = false;
                });
        },

        async getStorefrontDomain() {
            if (!this.salesChannel.configuration.salesChannelDomainId) {
                return;
            }

            this.storefrontDomainUrl = (await this.globalDomainRepository.get(
                this.salesChannel.configuration.salesChannelDomainId,
                Shopware.Context.api
            ))?.url;
        },

        // Page methods:
        getFeedUrl(feed) {
            const _feed = this.exportFeeds[feed] || this.salesChannel.configuration.feeds[feed] || false;
            if (!_feed || _feed.file === null) {
                return '';
            }

            const {feedDirectory, salesChannelDomainId} = this.salesChannel.configuration;
            if (!feedDirectory || !salesChannelDomainId) {
                return '';
            }

            const urlPath = `/hello-retail/${feedDirectory}/${_feed.file}`;
            return this.storefrontDomainUrl + urlPath;
        },

        onStorefrontSelectionChange(storefrontSalesChannelId, salesChannel) {
            if (!this.isHelloRetail) {
                this.$super('onStorefrontSelectionChange', storefrontSalesChannelId, salesChannel);
                return;
            }

            if (!storefrontSalesChannelId) {
                return;
            }

            // Same as $super, but no reason to do an extra request as Shopware does.
            this.salesChannel.languageId = salesChannel.languageId;
            this.salesChannel.currencyId = salesChannel.currencyId;
            this.salesChannel.paymentMethodId = salesChannel.paymentMethodId;
            this.salesChannel.shippingMethodId = salesChannel.shippingMethodId;
            this.salesChannel.countryId = salesChannel.countryId;
            this.salesChannel.navigationCategoryId = salesChannel.navigationCategoryId;
            this.salesChannel.navigationCategoryVersionId = salesChannel.navigationCategoryVersionId;
            this.salesChannel.customerGroupId = salesChannel.customerGroupId;
            // ~Same as $super

            this.storefrontDomainUrl = salesChannel.domains.first().url;
            this.$set(this.salesChannel.configuration, 'salesChannelDomainId', salesChannel.domains.first()?.id);

            if (!this.salesChannel.accessKey) {
                this.onGenerateKeys();
            }
        },
    }
});