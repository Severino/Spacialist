<template>
    <div>
        <div
            class="input-group"
            v-if="collapsible"
        >
            <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="disabled"
                @click="toggleList()"
            >
                <div v-show="!state.expanded">
                    <i class="fas fa-fw fa-caret-up" />
                    <span v-if="v.value.length">
                        ({{ v.value.length }})
                    </span>
                </div>
                <div v-show="state.expanded">
                    <i class="fas fa-fw fa-caret-down" />
                </div>
            </button>
            <input
                v-model="state.input"
                type="text"
                class="form-control"
                :disabled="disabled"
                @keydown.enter="addListEntry()"
            >
            <button
                v-if="!disabled"
                type="button"
                class="btn btn-outline-primary"
                :disabled="!state.valid"
                @click="addListEntry()"
            >
                <i class="fas fa-fw fa-plus" />
            </button>
        </div>
        <ol
            v-if="(collapsible && state.expanded) || !collapsible"
            class="list-group"
        >
            <li
                v-for="(item, i) in v.value"
                :key="i"
                class="list-group-item d-flex gap-3 justify-content-between align-items-center p-0"
                :class="{ 'list-group-numbered': numbered }"
            >
                <!-- eslint-disable-next-line vue/no-v-html -->
                <input
                    type="text"
                    class="form-control border-0"
                    :value="item"
                    @input="(event) => updateItem(event, i)"
                />
                <a
                    v-if="!disabled"
                    class="text-secondary px-3"
                    href="#"
                    @click.prevent="removeListEntry(i)"
                >
                    <i class="fas fa-fw fa-x fa-xs" />
                </a>
            </li>
        </ol>
        <div class="input-group mt-2">
            <input
                v-model="state.input"
                type="text"
                class="form-control"
                :disabled="disabled"
                @keydown.enter="addListEntry()"
            >
            <button
                v-if="!disabled"
                type="button"
                class="btn btn-outline-primary"
                :disabled="!state.valid"
                @click="addListEntry()"
            >
                <i class="fas fa-fw fa-plus" />
            </button>
        </div>
    </div>
</template>

<script>
    import {
        computed,
        reactive,
        toRefs,
        watch,
    } from 'vue';

    import { useI18n } from 'vue-i18n';

    import {
        createAnchorFromUrl,
    } from '@/helpers/helpers.js';

    export default {
        props: {
            name: {
                type: String,
                required: false,
                default: null
            },
            entries: {
                type: Array,
                default: _ => new Array(),
            },
            disabled: {
                type: Boolean,
            },
            collapsible: {
                type: Boolean,
            },
            numbered: {
                type: Boolean
            }
        },
        emits: ['change'],
        setup(props, context) {
            const { t } = useI18n();
            const {
                entries,
            } = toRefs(props);
            // FETCH

            // FUNCTIONS
            const addListEntry = _ => {
                if(!state.valid) return;
                v.value.push(state.input);
                v.meta.dirty = true;
                state.input = '';
                v.meta.validated = true;
            };
            const removeListEntry = index => {
                v.value.splice(index, 1);
                v.meta.dirty = true;
                v.meta.validated = true;
            };
            const toggleList = _ => {
                state.expanded = !state.expanded;
            };
            const resetFieldState = _ => {
                // make sure to keep original array and only re-push values
                v.value.length = 0;
                v.value.push(...state.initialValue.slice());

                v.meta.dirty = false;
                v.meta.valid = true;
                v.meta.validated = false;
            };
            const undirtyField = _ => {
                state.initialValue = v.value.slice();

                v.meta.dirty = false;
                v.meta.valid = true;
                v.meta.validated = false;
            };

            // DATA
            const state = reactive({
                input: '',
                initialValue: entries.value.slice(),
                expanded: false,
                valid: computed(_ => !!state.input),
            });
            const v = reactive({
                meta: {
                    dirty: false,
                    valid: true,
                    validated: false,
                },
                value: entries.value.slice(),
            });

            const updateItem = (event, index) => {
                const text = event.currentTarget.value
                v.value[index] = text
                if(checkDirty()) {
                    v.meta.dirty = true
                    context.emit('change', {
                        dirty: v.meta.dirty,
                        valid: v.meta.valid,
                        value: v.value,
                    });
                } else {
                    if(v.meta.dirty) {
                        v.meta.dirty = false;
                        context.emit('change', {
                            dirty: v.meta.dirty,
                            valid: v.meta.valid,
                            value: v.value,
                        });
                    }
                }
            }

            const checkDirty = () => {
                if(v.value.length != state.initialValue.length) return true;
                return !v.value.every((val, index) => val === state.initialValue[index])
            }

            watch(v.value, (newValue, oldValue) => {
                // only emit @change event if field is validated (required because Entity.vue components)
                // trigger this watcher several times even if another component is updated/validated
                if(!v.meta.validated) return;
                context.emit('change', {
                    dirty: v.meta.dirty,
                    valid: v.meta.valid,
                    value: v.value,
                });
            }, { deep: true });
            watch(_ => entries.value, (newValue, oldValue) => {
                state.initialValue = newValue.slice();
                resetFieldState();
            });

            // RETURN
            return {
                t,
                // HELPERS
                createAnchorFromUrl,
                // LOCAL
                addListEntry,
                removeListEntry,
                toggleList,
                resetFieldState,
                undirtyField,
                updateItem,
                // STATE
                state,
                v,
            };
        },
    };
</script>
