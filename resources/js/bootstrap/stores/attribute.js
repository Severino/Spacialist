import {
    defineStore,
} from 'pinia';

import useEntityStore from './entity.js';
import usePluginStore from './plugin.js';
import useSystemStore from './system.js';

import {
    addAttribute,
    deleteAttribute,
} from '@/api.js';

import {
    sortConcepts,
} from '@/helpers/helpers.js';

export const useAttributeStore = defineStore('attribute', {
    state: _ => ({
        attributes: [],
        attributeTypes: [],
        attributeSelections: {},
    }),
    getters: {
        getTableAttributeTypes: state => state.attributeTypes.filter(type => type.in_table),
    },
    actions: {
        getAttribute(id) {
            if(!id) return {};
            return this.attributes.find(a => a.id == id) || {};
        },
        getAttributeName(id) {
            const attribute = this.getAttribute(id);
            if(!attribute || !attribute.thesaurus_url) return '';

            return useSystemStore().translateConcept(attribute.thesaurus_url);
        },
        getAttributeListBy(type) {
            const filter = type == 'system';
            return this.attributes.filter(attribute => attribute.is_system == filter);
        },
        getAttributeSelection(aid) {
            return this.attributeSelections[aid];
        },
        getAttributeSelections(attributes) {
            const selections = this.attributeSelections;
            const filteredSelection = {};
            for(let k in selections) {
                if(attributes.findIndex(a => a.id == k) > -1) {
                    filteredSelection[k] = selections[k];
                }
            }
            return filteredSelection;
        },
        isFromPlugin(datatype) {
            console.log(datatype)
            return !!usePluginStore().registeredPluginAttributes?.[datatype];
        },
        getPluginAttributeLabel(datatype) {
            const attributeType = this.attributeTypes.find(attributeType => {
                return attributeType.datatype == datatype && !!attributeType.plugin;
            });

            return attributeType?.label;
        },
        setAttributes(attributes) {
            this.attributes = attributes;
        },
        setAttributeTypes(attributeTypes) {
            this.attributeTypes = attributeTypes;
        },
        setAttributeSelection(attributeId, selection, isNested) {
            if(isNested) {
                for(let k in selection) {
                    this.attributeSelections[k] = selection[k].sort(sortConcepts);
                }
            } else {
                this.attributeSelections[attributeId] = selection.sort(sortConcepts);
            }
        },
        setAttributeSelections(attributeSelections) {
            for(let k in attributeSelections) {
                attributeSelections[k].sort(sortConcepts);
            }
            this.attributeSelections = attributeSelections;
        },
        increaseEntityTypeCounter(attributeId) {
            const attribute = this.attributes.find(a => a.id == attributeId);
            if(attribute) {
                attribute.entity_types_count++;
            }
        },
        decreaseEntityTypeCounter(attributeId) {
            const attribute = this.attributes.find(a => a.id == attributeId);
            if(attribute) {
                attribute.entity_types_count--;
            }
        },
        async addAttribute(attribute) {
            return addAttribute(attribute).then(data => {
                this.attributes.push(data.attribute);
                if(data.selection) {
                    const nested = data.attribute.datatype == 'table';
                    const id = data.attribute.id;
                    const selection = data.selection;
                    this.setAttributeSelection(id, selection, nested);
                }
                return data;
            });
        },
        async deleteAttribute(attributeId) {
            return deleteAttribute(attributeId).then(_ => {
                const idx = this.attributes.findIndex(a => a.id == attributeId);
                if(idx > -1) {
                    this.attributes.splice(idx, 1);
                }
                // Also remove from entity type attribute lists
                useEntityStore().removeAttributeFromEntityTypes(attributeId);
            });
        },
    },
});

export default useAttributeStore;