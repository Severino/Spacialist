<template>
    <div class="alert alert-danger error-list">
        <header class="fw-bold">
            {{ header }}
        </header>
        <ul
            v-if="hasItems"
            class="mb-0"
        >
            <li
                v-for="(item, idx) in items"
                :key="idx"
            >
                {{ item }}
            </li>
        </ul>
    </div>
</template>

<script>
    import { computed } from 'vue';

    export default {
        props: {
            headerSeparator: {
                type: String,
                default: ':'
            },
            separator: {
                type: String,
                default: ','
            },
            value: {
                type: String,
                required: true
            }
        },
        setup(props) {
            const header = computed(_ => {
                return props.value.split(props.headerSeparator)[0].trim();
            });

            const items = computed(_ => {
                // Replace text inside double curly braces with placeholders
                const rowRegex = RegExp('{{(.*?)}} => {{(.*?)}},*\s*', 'g');
                const variables = {};
                let counter = 1;
                const itemMatches = props.value.matchAll(rowRegex)
                
                const list = [];
                
                itemMatches.forEach(([match, attribute, value]) => {
                    console.log('Match:', match);
                    list.push(`${attribute} → ${value}`);
                    counter++;
                });
                
                return list;
            });

            const hasItems = computed(_ => {
                return items.value.length > 0 && items.value[0] && items.value[0].trim() !== '';
            });

            return {
                hasItems,
                header,
                items,
            };
        }
    };
</script>