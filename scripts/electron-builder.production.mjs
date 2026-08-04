import config from '../vendor/nativephp/desktop/resources/electron/electron-builder.mjs';

if (process.env.NATIVEPHP_SKIP_WIN_SIGNING === 'true') {
    config.win = {
        ...(config.win ?? {}),
        signAndEditExecutable: false,
        signExts: ['!.exe'],
    };
    delete config.afterSign;
}

export default config;
