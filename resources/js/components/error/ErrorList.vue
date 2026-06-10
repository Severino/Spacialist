<template>
    <div class="alert alert-danger error-list p-0 m-0">
        <header class="p-2">
            <span
                class="opacity-50 me-3"
                v-if="lineNumber"
            >
                Row {{ lineNumber }}
            </span>
            {{ header }}
            <span
                v-if="hasItems"
                class="fst-italic"
            >({{
                items.length }} errors)</span>
        </header>
        <template v-if="hasItems">
            <hr class="m-0 mb-3" />
            <ol class="mb-0">
                <li
                    v-for="(item, idx) in items"
                    :key="idx"
                    style="font-size:0.8rem;"
                    class="ps-2 pe-4 mb-2 opacity-75"
                >
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <div class="fst-italic">{{ item.key }}</div>
                        has invalid value
                        <span
                            class="bg-light rounded py-0 px-2"
                            style="font-size: 0.65rem;"
                        >{{ item.value }}</span>
                    </div>
                </li>
            </ol>
        </template>
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
            const fullHeader = computed(_ => {
                return props.value.split(props.headerSeparator)[0].trim();
            });

            const header = computed(_ => {
                return fullHeader.value.replace(/^\[(\d+)\]/, '').trim();
            });

            const lineNumber = computed(_ => {
                return fullHeader.value.match(/^\[(\d+)\]/)?.[1];
            });

            const items = computed(_ => {
                let items = [];
                try {
                    // Replace text inside double curly braces with placeholders
                    const preservationRegex = RegExp('{{(.*?)}}', 'g');
                    const variables = {};
                    let counter = 1;
                    const preservationMatches = props.value.replace(preservationRegex, (match, p1 = '') => {
                        const key = `$${counter++}`;
                        variables[key] = p1;
                        return key;
                    });

                    console.log('preservationMatches', preservationMatches, variables);
                    // Normally the body should not need to be joied, we keep it for better 
                    // error resistance.
                    let [header, ...body] = preservationMatches.split(props.headerSeparator);
                    body = body.join();
                    const lines = body.split(",").filter(line => line.trim().length > 0);
                    console.log('lines', lines);
                    items = lines.map(line => {
                        line = line.trim();
                        const parts = line.split('=>').map(part => part.trim());
                        const [keyId, valueId] = parts;
                        return { key: variables[keyId] ?? "N/A", value: variables[valueId] ?? "N/A" };
                    })
                } catch(e) {
                    console.error('Error parsing error list:', e);
                }

                return items
            });

            const hasItems = computed(_ => {
                return items.value.length > 0;
            });

            return {
                hasItems,
                header,
                items,
                lineNumber,
            };
        }
    };
</script>