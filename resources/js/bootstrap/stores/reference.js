import { defineStore } from 'pinia';

import useEntityStore from './entity.js';

import {
    addReference,
    deleteReferenceFromEntity,
    updateReference,
} from '@/api.js';

export const useReferenceStore = defineStore('reference', {
    state: _ => ({}),
    getters: {},
    actions: {
        currentEntityReferences() {
            const entity = useEntityStore().selectedEntity;
            return entity?.references?.on_entity || [];
        },
        currentAttributeReferences() {
            const entity = useEntityStore().selectedEntity;
            if(!entity?.references) return [];
            const {
                on_entity,
                ...attributedReferences
            } = entity.references;
            return attributedReferences;
        },
        get(entityId, attributeUrl = null) {
            const entity = useEntityStore().getEntity(entityId);
            if(attributeUrl) {
                return entity?.references?.[attributeUrl] || [];
            } else {
                return entity?.references?.on_entity || [];
            }
        },
        handleAdd(entityId, attributeUrl, data) {
            const entity = useEntityStore().getEntity(entityId);
            if(!entity) {
                console.error(`Entity ${entityId} not found in store. Cannot add reference.`);
            }
            
            // If we add, we need the same object reference so we can trigger
            // a entity refresh in the entity store to trigger the reactivity of the entity object.
            const references = entity.references || {};
            if(attributeUrl) {
                references[attributeUrl] = references[attributeUrl] || [];
                references[attributeUrl].push(data);
            } else {
                references.on_entity = references.on_entity || [];
                references.on_entity.push(data);
            }

            useEntityStore().refreshEntity(entityId, {
                ...entity,
                references: references,
            })
        },
        handleDelete(entityId, attributeUrl, referenceId) {
            const references = this.getReferences(entityId, attributeUrl);
            const idx = references.findIndex(ref => ref.id == referenceId);
            if(idx > -1) {
                references.splice(idx, 1);
            }
        },
        handleUpdate(entityId, attributeUrl, reference) {
            const references = this.getReferences(entityId, attributeUrl);
            const storedReference = references.find(ref => ref.id == reference.id);
            if(storedReference != undefined) {
                Object.assign(storedReference, reference);
            } else {
                // In an edge case this could happen.
                this.handleAdd(entityId, attributeUrl, data);
            }
        },
        getReferences(entityId, attributeUrl = null) {
            const entity = useEntityStore().getEntity(entityId);
            if(attributeUrl) {
                return entity?.references?.[attributeUrl] || [];
            } else {
                return entity?.references?.on_entity || [];
            }
        },
        async add(entityId, attributeId, attributeUrl, refData) {
            return addReference(entityId, attributeId, refData).then(data => {
                this.handleAdd(entityId, attributeUrl, data);
            });
        },
        async update(entityId, attributeUrl, reference) {
            console.trace('update', entityId, attributeUrl, reference);
            return updateReference(reference)
                .then(data => {
                    this.handleUpdate(entityId, attributeUrl, data);
                });
        },
        async delete(entityId, attributeUrl, reference) {
            return deleteReferenceFromEntity(reference.id).then(_ => {
                this.handleDelete(entityId, attributeUrl, reference.id);
            });
        },
    },
});

export default useReferenceStore;