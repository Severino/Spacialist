/**
 * A dynalot is a special type of plugin slot that allows plugins to provide components to be rendered 
 * at specific places in the UI, but without a fixed position. Instead, the components provided by the 
 * plugins are rendered based on the current context, e.g. the entity detail page, where we want to render
 * additional tabs depending of the amount of child entities.
 */

export const availableDynalots = () => {
    return [
        'entity-detail-tabs',
    ];
};

export const validateDynalot = (options) => {
    if(!options.slot) {
        throw new Error('No slot for Dynalot provided!');
    }

    if(!availableDynalots().includes(options.slot)) {
        throw new Error('Invalid slot for Dynalot provided: ' + options.slot);
    }
    
    if(!options.update || typeof options.update !== 'function') {
        throw new Error('No update function for Dynalot provided!');
    }
    if(!options.getComponent || typeof options.getComponent !== 'function') {
        throw new Error('No getComponent function for Dynalot provided!');
    }
};