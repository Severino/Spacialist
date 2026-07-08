<template>
    <div class="entity-importer-settings d-flex flex-column gap-2">
        <div>
            <label
                for="import-entity-type"
                class="form-label"
            >
                {{ t('main.importer.selected_entity_type') }}
            </label>
            <multiselect
                id="import-entity-type"
                :classes="multiselectResetClasslist"
                :disabled="disabled"
                :hide-selected="true"
                :label="'thesaurus_url'"
                :object="true"
                :options="sortedAvailableEntityTypes"
                :placeholder="t('global.select.placeholder')"
                :searchable="true"
                :track-by="'id'"
                :value-prop="'id'"
                :value="entityType"
                :append-to-body="true"
                @change="value => $emit('update:entityType', value)"
            >
                <template #option="{ option }">
                    {{ option._label }}
                </template>
                <template #singlelabel="{ value }">
                    <div class="multiselect-single-label">
                        {{ value._label }}
                    </div>
                </template>
            </multiselect>
        </div>
        <div>
            <label
                for="import-entity-name"
                class="form-label d-flex"
            >
                {{ t('main.importer.column_entity_name') }}

                <ValuesMissingIndicator
                    class="ms-2"
                    :required="true"
                    :allow-empty="false"
                    :missing="getMissing('entityName')"
                    :total="getTotal('entityName')"
                />
            </label>
            <multiselect
                id="import-entity-name"
                :classes="multiselectResetClasslist"
                :disabled="disabled"
                :hide-selected="true"
                :options="sortedAvailableColumns"
                :placeholder="t('global.select.placeholder')"
                :searchable="true"
                :value="entityName"
                :append-to-body="true"
                @change="value => $emit('update:entityName', value)"
            />
        </div>
        <div>
            <label
                for="import-entity-parent"
                class="form-label d-flex"
            >
                {{ t('main.importer.column_entity_parent') }}
                <ValuesMissingIndicator
                    class="ms-2"
                    :missing="getMissing('entityParent')"
                    :total="getTotal('entityParent')"
                />
            </label>
            <multiselect
                id="import-entity-parent"
                :classes="multiselectResetClasslist"
                :disabled="disabled"
                :hide-selected="true"
                :value="entityParent"
                :options="sortedAvailableColumns"
                :placeholder="t('global.select.placeholder')"
                :searchable="true"
                :append-to-body="true"
                @change="(value) => $emit('update:entityParent', value, entityParent)"
            />
        </div>
    </div>
    <div>
        <label
            for="import-entity-attribution"
            class="form-label d-flex"
        >
            {{ t('main.importer.column_entity_attribution') }}
            <ValuesMissingIndicator
                class="ms-2"
                :missing="getMissing('entityAttribution')"
                :total="getTotal('entityAttribution')"
            />
        </label>
        <multiselect
            id="import-entity-attribution"
            :classes="multiselectResetClasslist"
            :disabled="disabled"
            :hide-selected="true"
            :value="entityAttribution"
            :options="sortedAvailableColumns"
            :placeholder="t('global.select.placeholder')"
            :searchable="true"
            :append-to-body="true"
            @change="(value) => $emit('update:entityAttribution', value, entityAttribution)"
        />
    </div>
    <div>
        <label
            for="import-entity-licence"
            class="form-label d-flex"
        >
            {{ t('main.importer.column_entity_licence') }}
            <ValuesMissingIndicator
                class="ms-2"
                :missing="getMissing('entityLicence')"
                :total="getTotal('entityLicence')"
            />
        </label>
        <multiselect
            id="import-entity-licence"
            :classes="multiselectResetClasslist"
            :disabled="disabled"
            :hide-selected="true"
            :value="entityLicence"
            :options="sortedAvailableColumns"
            :placeholder="t('global.select.placeholder')"
            :searchable="true"
            :append-to-body="true"
            @change="(value) => $emit('update:entityLicence', value, entityLicence)"
        />
    </div>
</template>

<script>
    import { computed } from 'vue';
    import { useI18n } from 'vue-i18n';

    import {
        multiselectResetClasslist,
        translateConcept,
    } from '@/helpers/helpers.js';

    import ValuesMissingIndicator from './ValuesMissingIndicator.vue';

    export default {
        components: {
            ValuesMissingIndicator
        },
        props: {
            disabled: {
                type: Boolean,
                default: false,
            },
            stats: {
                type: Object,
                required: true,
            },
            entityParent: {
                type: String,
                default: '',
            },
            entityAttribution: {
                type: String,
                default: '',
            },
            entityLicence: {
                type: String,
                default: '',
            },
            entityName: {
                type: String,
                default: '',
            },
            entityType: {
                type: Object,
                default: null,
            },
            availableEntityTypes: {
                type: Array,
                required: true,
            },
            availableColumns: {
                type: Array,
                required: true,
            },
        },
        emits: [
            'update:entityType',
            'update:entityName',
            'update:entityParent',
            'update:entityAttribution',
            'update:entityLicence',
        ],
        setup(props) {
            const { t } = useI18n();

            const getTotal = attr => {
                let val = 0;
                if(props.stats[attr]?.total != undefined)
                    val = props.stats[attr].total;
                return val;
            }

            const getMissing = attr => {
                let val = 0;
                if(props.stats[attr]?.missing != undefined)
                    val = props.stats[attr].missing;
                return val;
            }

            const sortedAvailableEntityTypes = computed(_ => {
                const options = props.availableEntityTypes;
                for(const option of options) {
                    option._label = translateConcept(option.thesaurus_url);
                }

                return options.sort((a, b) => {
                    return a._label.localeCompare(b._label);
                });
            });

            const sortedAvailableColumns = computed(_ => {
                const options = [];
                for(const key in props.availableColumns) {
                    options.push(props.availableColumns[key]);
                }

                return Object.values(options).sort((a, b) => {
                    return a.localeCompare(b);
                });
            });

            return {
                t,
                multiselectResetClasslist,
                getTotal,
                getMissing,
                sortedAvailableColumns,
                sortedAvailableEntityTypes,
            };
        },
    };
</script>