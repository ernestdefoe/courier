import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';

const t = (k: string) => app.translator.trans('ernestdefoe-courier.admin.' + k);

export default [
  new Extend.Admin()
    .setting(() => ({
      setting: 'courier.site_key',
      type: 'text',
      label: t('settings.site_key_label'),
      help: t('settings.site_key_help'),
    }))
    .setting(() => ({
      setting: 'courier.relay_url',
      type: 'text',
      label: t('settings.relay_url_label'),
      help: t('settings.relay_url_help'),
    })),
];
