export const attributeStringConversions = ["string", "stringf", "richtext", "url"]

/**
 * Creates a conversionmap of all available and convertible datatypes.
 * @returns {object} - in the form of {datatype: available-datatypes}. note that available-datatypes always include datatype itself.
 */
export function getAttributeConvertationMap() {
    const availableConversions = [
        attributeStringConversions,
    ]

    const map = {};
    availableConversions.forEach(conversionsArray => {
        conversionsArray.forEach((datatype) => {
            map[datatype] = conversionsArray;
        })
    });
    
    return map;
} 


/**
 * Get the conversions for any datatype. 
 * If no conversions are specified it can only be converted
 * into itself.
 * 
 * @param {string} datatype - name of the datatype 
 * @returns {array} - Array of all possible conversions including itself
 */
export function getConversionFor(datatype) {
    const conversionMap = getAttributeConvertationMap()
    console.log(conversionMap)
    return conversionMap[datatype] ?? [datatype]
}