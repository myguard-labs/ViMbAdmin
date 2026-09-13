'use strict';

// Observe ownership without retaining element keys or requiring nondeterministic
// GC. A strong indexed registry cannot satisfy this oracle in any browser lane.
var dataTablesEventStores = [];
var nativeWeakMapSet = WeakMap.prototype.set;
WeakMap.prototype.set = function(key, value) {
    if (key && key.nodeType && Array.isArray(value) && dataTablesEventStores.indexOf(this) === -1) {
        dataTablesEventStores.push(this);
    }
    return nativeWeakMapSet.call(this, key, value);
};
function hasDataTablesHandlers(node) {
    return dataTablesEventStores.some(function(store) {
        var handlers = store.get(node);
        return handlers && handlers.length > 0;
    });
}
