<template>
    <vue-final-modal
        class="modal-container modal"
        content-class="sp-modal-content sp-modal-content-xl h-100"
        name="map-picker-modal"
    >
        <div class="sp-modal-content sp-modal-content-xl h-100">
            <div class="modal-header">
                <h5 class="modal-title">
                    {{ t('main.entity.attributes.set_location') }}
                </h5>
                <button
                    type="button"
                    class="btn-close"
                    aria-label="Close"
                    data-bs-dismiss="modal"
                    @click="closeModal()"
                />
            </div>
            <div class="modal-body d-flex flex-column">
                <basic-map
                    class="flex-grow-1 overflow-hidden"
                    :wkt="data.value"
                />
                <div class="mt-2">
                    WKT: <pre class="m-0"><code class="fs-1r">{{ data.value }}</code></pre>
                </div>
            </div>
            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    data-bs-dismiss="modal"
                    @click="closeModal()"
                >
                    <i class="fas fa-fw fa-times" /> {{ t('global.close') }}
                </button>
            </div>
        </div>
    </vue-final-modal>
</template>

<script setup>
    import { useI18n } from 'vue-i18n';

    import BasicMap from '@/components/map/Basic.vue';

    defineProps({
        data: {
            required: true,
            type: Object,
        },
    });
    const emit = defineEmits(['confirm', 'closing']);

    const { t } = useI18n();

    const closeModal = _ => {
        emit('closing', false);
    };
</script>
