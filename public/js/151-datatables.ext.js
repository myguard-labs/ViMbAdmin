/* Numeric HTML sorting for ViMbAdmin. DataTables extensions use the MIT licence.
 * Original numeric HTML sorting: Allan Jardine, SpryMedia Ltd.
 */
Object.assign(DataTable.ext.type.order, {
    'num-html-pre': function(value) {
        return parseFloat(String(value).replace(/<[\s\S]*?>/g, ''));
    },
    'num-html-asc': function(a, b) { return a < b ? -1 : a > b ? 1 : 0; },
    'num-html-desc': function(a, b) { return a < b ? 1 : a > b ? -1 : 0; }
});
