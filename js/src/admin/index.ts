/**
 * Admin entry.
 *
 * 🚨 Declared through the `extend` export, NOT `app.initializers.add(...)` +
 * `app.extensionData`. In Flarum 2 the initializer runs before
 * `app.extensionData` exists, so the older pattern throws and the whole
 * extension is reported as failing to initialise.
 */
export { default as extend } from './extend';
