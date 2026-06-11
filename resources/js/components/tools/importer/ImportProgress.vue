<template>
    <div class="position-relative">
        <div
            class="progress"
            style="height: 40px;"
            :class="{ 'opacity-50': disabled }"
            :aria-disabled="disabled"
        >
            <div
                class="progress-bar"
                role="progressbar"
                :style="{ width: `${progressPercent}%` }"
                :aria-valuenow="processed ?? 0"
                aria-valuemin="0"
                :aria-valuemax="total ?? 1"
            />
        </div>
        <span
            class="position-absolute start-0 top-50 w-100 text-center text-white translate-middle-y fw-bold"
            style="text-shadow: black 0 0 3px;"
        >
            {{ progessText }}
        </span>
    </div>
</template>

<script setup>
    import { computed, onMounted, onUnmounted, ref } from 'vue';
    import { subscribeTo } from '@/helpers/websocket';
    import { unsubscribeFrom } from '@/helpers/websocket';

    defineProps({
        disabled: {
            type: Boolean,
            default: false,
        }
    });

    const processed = ref(0);
    const total = ref(0);
    const topic = ref('');

    onMounted(() => {
        subscribeTo('entity-import-progress', true, 'EntityImportProgress', (data) => {
            console.log('Import progress update', data);
            if(data.processed !== undefined) {
                processed.value = data.processed;
            }
            if(data.total !== undefined) {
                total.value = data.total;
            }

            if(data.topic !== undefined) {
                topic.value = data.topic;
            }
        });
    });

    const progessText = computed(() => {
        let value = '0%';
        if(total.value > 0) {
            value = `${(processed.value / total.value * 100).toFixed(2)}%`;
        }

        if(topic.value) {
            value = `${topic.value} - ${value}`;
        }

        return value;
    });

    const progressPercent = computed(() => {
        if(total.value <= 0) {
            return 0;
        }

        return Math.min(100, Math.max(0, (processed.value / total.value) * 100));
    });

    onUnmounted(() => {
        unsubscribeFrom('entity-import-progress');
    });
</script>