import {
    defineStore,
} from 'pinia';

import useEntityStore from './entity.js';
import useSystemStore from './system.js';

import {
    addAttribute,
    deleteAttribute,
    patchAttributeDataModel,
} from '@/api.js';

import {
    sortConcepts,
} from '@/helpers/helpers.js';
import usePluginStore from './plugin.js';

export const useAttributeStore = defineStore('attribute', {
    state: _ => ({
        attributes: [],
        attributeTypes: [],
        attributeSelections: {},
    }),
    getters: {
        getAttribute: state => id => {
            if(!id) return {};
            return state.attributes.find(a => a.id == id) || {};
        },
        getAttributeName(state) {
            return id => {
                const attribute = this.getAttribute(id);
                if(!attribute || !attribute.thesaurus_url) return '';

                return useSystemStore().translateConcept(attribute.thesaurus_url);
            };
        },
        getAttributeListBy: state => type => {
            const filter = type == 'system';
            return state.attributes.filter(attribute => attribute.is_system == filter);
        },
        getAttributeSelection: state => aid => {
            return state.attributeSelections[aid];
        },
        getAttributeSelections: state => attributes => {
            const selections = state.attributeSelections;
            const filteredSelection = {};
            for(let k in selections) {
                if(attributes.findIndex(a => a.id == k) > -1) {
                    filteredSelection[k] = selections[k];
                }
            }
            return filteredSelection;
        },
        getTableAttributeTypes: state => state.attributeTypes.filter(type => type.in_table),
        isFromPlugin: state => datatype => {
            const registeredPluginAttributes = usePluginStore().registeredAttributes;
            return !!usePluginStore().registeredAttributes[datatype];
        },
        getPluginAttributeLabel: state => datatype => {
            const attributeType = state.attributeTypes.find(attributeType => {
                return attributeType.datatype == datatype && !!attributeType.plugin;
            });

            return attributeType?.label ?? datatype ?? "N / A";
        },
    },
    actions: {
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
        /**
         * Replaces or, if an attribute with that id doesn't exists, inserts an attribute. 
         * @param {object} attribute - Attribute object that should be replaced. 
         */
        replaceAttribute(attribute) {
            const index = this.attributes.findIndex(other_attribute => other_attribute.id === attribute.id)
            if(index >= 0) {
                // There may be additional properties which would be lost with a simple replacement e.g. the entity_type_count 
                const mergedAttributes = Object.assign({}, this.attributes[index], attribute)
                this.attributes.splice(index, 1, mergedAttributes);
            } else {
                this.attributes.push(attribute)
            }
            this.updateAttributesOnEntityTypeAttributes([attribute]);
        },
        /**
         * Changes the attribute's datatype, if the target datatype is supported.
         * @param {number} attributeId - Attribute to update. 
         * @param {string} datatype - Target datatype. 
         * @returns {void}
         */
        async patchType(attributeId, datatype) {
            return patchAttributeDataModel(attributeId, { datatype })
                .then(attribute => this.replaceAttribute(attribute))
        },
        /**
         * Updates all related entityTypeAttributes to represent the correct datatype.
         * 
         * This ensures that all attributes that are already rendered on the entity types
         * are updated correctly and immediately show the new datatype when the attribute
         * properties were changed.
         * 
         * @param {array} attributes - A list of attributes that should be updated. 
         */
        updateAttributesOnEntityTypeAttributes(attributes) {
            const entityTypeAttributes = useEntityStore().entityTypeAttributeMap
            for(const attribute of attributes) {
                console.log(entityTypeAttributes)
                const entityAttributes = entityTypeAttributes[attribute.id];
                for(const entityTypeId in entityAttributes) {
                    const entityAttribute = entityAttributes[entityTypeId];
                    // We override all attribute data on the entityAttribute
                    // the attribute data just lives on the root level.
                    Object.assign(entityAttribute, attribute)
                }
            }
        }
    },
});

export default useAttributeStore;