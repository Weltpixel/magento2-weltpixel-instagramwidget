var config = {
    map: {
        '*': {
            shufflejs: 'WeltPixel_InstagramWidget/js/Shuffle',
            polyfill: 'WeltPixel_InstagramWidget/js/Polyfill',
            instagramFeedBasic: 'WeltPixel_InstagramWidget/js/instagramFeedBasic',
            instagramFeedApi: 'WeltPixel_InstagramWidget/js/instagramFeedApi'
        }
    },
    shim: {
        shufflejs : {
            deps: ['polyfill']
        },
        instagramFeedBasic: {
            deps: ['jquery']
        },
        instagramFeedApi: {
            deps: ['jquery']
        }
    }
};
