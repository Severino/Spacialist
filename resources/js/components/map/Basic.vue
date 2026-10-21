<template>
    <div
        ref="mapElement"
        class="basic-map"
    />
</template>

<script setup>
    import {
        onBeforeUnmount,
        onMounted,
        ref,
        watch,
    } from 'vue';

    import Map from 'ol/Map';
    import View from 'ol/View';
    import WKT from 'ol/format/WKT';
    import TileLayer from 'ol/layer/Tile';
    import VectorLayer from 'ol/layer/Vector';
    import OSM from 'ol/source/OSM';
    import VectorSource from 'ol/source/Vector';
    import CircleStyle from 'ol/style/Circle';
    import Fill from 'ol/style/Fill';
    import Style from 'ol/style/Style';
    import Stroke from 'ol/style/Stroke';

    const props = defineProps({
        // WKT string, interpreted in `projection`
        wkt: {
            type: String,
            default: '',
        },
        projection: {
            type: String,
            default: 'EPSG:4326',
        },
    });

    const mapElement = ref(null);
    const wktFormat = new WKT();
    const source = new VectorSource();
    let map = null;
    let resizeObserver = null;

    const fitGeometry = _ => {
        if(!map || !source.getFeatures().length) return;
        const [width, height] = map.getSize() ?? [0, 0];
        if(!width || !height) return;
        map.getView().fit(source.getExtent(), {
            maxZoom: 17,
            padding: [20, 20, 20, 20],
        });
    };

    const showGeometry = _ => {
        console.log(props.wkt)
        source.clear();
        if(props.wkt) {
            try {
                let wkt = props.wkt;
                let projection = 3857
                const parts = props.wkt.split(";")
                if(parts.length > 1) {
                    wkt = parts[1];
                    const sridParts = parts[0].split("=")
                    console.log(sridParts);
                    if(sridParts.length > 1) {
                        projection = parseInt(sridParts[1])
                    }
                }

                console.log(projection, props.projection)
                const feature = wktFormat.readFeature(wkt, {
                    dataProjection: 'EPSG:' + projection,
                    featureProjection: map.getView().getProjection(),
                });
                
                const fill = new Fill({ color: 'rgb(255,0,0)' });
                const stroke = new Stroke({ color: '#000000', width: 1 });
                feature.setStyle(new Style({
                    image: new CircleStyle({
                        radius: 6,
                        fill,
                        stroke,
                    }),
                }));

                source.addFeature(feature);
            } catch(e) {
                console.error(e)
                // incomplete or invalid WKT while typing: show nothing
                return;
            }
        }
        fitGeometry();
    };

    onMounted(_ => {
        map = new Map({
            target: mapElement.value,
            layers: [
                new TileLayer({
                    source: new OSM(),
                }),
                new VectorLayer({
                    source,
                }),
            ],
            view: new View({
                center: [0, 0],
                zoom: 2,
            }),
        });
        showGeometry();

        // Inside modals the element has no size yet when mounted, so fit once it is laid out
        resizeObserver = new ResizeObserver(_ => {
            map.updateSize();
            fitGeometry();
        });
        resizeObserver.observe(mapElement.value);
    });

    onBeforeUnmount(_ => {
        if(resizeObserver) {
            resizeObserver.disconnect();
            resizeObserver = null;
        }
        if(map) {
            map.setTarget(null);
            map = null;
        }
    });

    watch(_ => props.wkt, showGeometry);
</script>

<style scoped>
    .basic-map {
        width: 100%;
        min-height: 200px;
    }
</style>
