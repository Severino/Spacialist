<template>
    <textarea
        :id="name"
        ref="textarea"
        v-model="v.value"
        class="form-control"
        :disabled="disabled"
        :name="name"
        :style="{
            resize: autoheight ? 'none' : 'vertical',
            overflowY: autoheight ? 'hidden' : 'auto',
        }"
        @input="(event) => updateHeight(event)"
    />
</template>

<script>
    import {
        reactive,
        toRefs,
        useTemplateRef,
        nextTick,
        onMounted,
        watch,
    } from 'vue';

    import { useField } from 'vee-validate';

    import * as yup from 'yup';

    export default {
        props: {
            name: {
                type: String,
                required: true,
            },
            disabled: {
                type: Boolean,
                required: false,
                default: false,
            },
            value: {
                type: String,
                required: true,
            },
            autoheight: Boolean
        },
        emits: ['change'],
        setup(props, context) {
            const {
                name,
                disabled,
                value,
            } = toRefs(props);
            // FETCH

            const textarea = useTemplateRef('textarea')

            // FUNCTIONS
            const updateHeight = () => {
                if(!props.autoheight || !textarea.value) return;

                textarea.value.style.height = 'auto';
                const styles = window.getComputedStyle(textarea.value);
                const borders = parseFloat(styles.borderTopWidth) + parseFloat(styles.borderBottomWidth);
                textarea.value.style.height = `${textarea.value.scrollHeight + borders}px`;
            };
            const resetFieldState = _ => {
                v.resetField({
                    value: value.value
                });
            };
            const undirtyField = _ => {
                v.resetField({
                    value: v.value,
                });
            };

            // DATA
            const {
                value: fieldValue,
                meta,
                resetField,
            } = useField(`strf_${name.value}`, yup.string(), {
                initialValue: value.value,
            });
            const state = reactive({

            });
            const v = reactive({
                value: fieldValue,
                meta,
                resetField,
            });

            watch(_ => value, (newValue, oldValue) => {
                resetFieldState();
            });
            watch(_ => v.value, (newValue, oldValue) => {
                nextTick(updateHeight);
                // only emit @change event if field is validated (required because Entity.vue components)
                // trigger this watcher several times even if another component is updated/validated
                if(!v.meta.validated) return;
                context.emit('change', {
                    dirty: v.meta.dirty,
                    valid: v.meta.valid,
                    value: v.value,
                });
            });

            onMounted(updateHeight);

            // RETURN
            return {
                // HELPERS
                // LOCAL
                resetFieldState,
                undirtyField,
                updateHeight,
                // STATE
                state,
                v,
            };
        },
    };
</script>
