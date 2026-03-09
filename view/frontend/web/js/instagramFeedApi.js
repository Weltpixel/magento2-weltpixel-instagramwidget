(function (factory) {
    if (typeof define === 'function' && define.amd) {
        // AMD. Register as an anonymous module depending on jQuery.
        define(['jquery'], factory);
    } else {
        // No AMD. Register plugin with global jQuery object.
        factory(jQuery);
    }
}(function($){
    var defaults = {
        'host': "https://graph.instagram.com/me/media",
        'token': '',
        'container': '',
        'display_captions': false,
        'callback': null,
        'on_error': console.error,
        'after': null,
        'items': 6,
        'image_new_tab': '',
        'image_padding': '',
        'image_alt_tag': 0,
        'image_alt_label': '',
        'image_lazy_load': false,
        'show_videos' : false,
        'show_like_count' : false,
        'useHashTagFilter' : false,
        'hashTagFilter' : '',
        'cache_time': 30,
        'instaServerFetchImageUrl': '',
        'lazy_load_placeholder_width': '100%'
    };
    var escape_map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
        '/': '&#x2F;',
        '`': '&#x60;',
        '=': '&#x3D;'
    };
    var nextPageUrl  = false;
    var nextPageData = false;

    var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

    function escape_string(str) {
        return str.replace(/[&<>"'`=\/]/g, function (char) {
            return escape_map[char];
        });
    }

    /**
     * Cache management
     */
    function set_cache(options, data){
        try {
            // Try to store in localStorage with chunking if needed
            const MAX_CHUNK_SIZE = 5 * 1024; // 512KB per chunk
            const dataStr = JSON.stringify(data);

            // Clear any existing chunks for this cache key
            for (let key in localStorage) {
                if (key.startsWith(options.cache_data_key)) {
                    localStorage.removeItem(key);
                }
            }

            if (dataStr.length > MAX_CHUNK_SIZE) {
                // Split into chunks
                const chunks = Math.ceil(dataStr.length / MAX_CHUNK_SIZE);
                for (let i = 0; i < chunks; i++) {
                    const chunk = dataStr.substr(i * MAX_CHUNK_SIZE, MAX_CHUNK_SIZE);
                    localStorage.setItem(`${options.cache_data_key}_chunk_${i}`, chunk);
                }
                localStorage.setItem(`${options.cache_data_key}_chunks`, chunks);
            } else {
                localStorage.setItem(options.cache_data_key, dataStr);
            }
            localStorage.setItem(options.cache_time_key, new Date().getTime());
        } catch (e) {
            console.warn('Instagram Feed: Local storage quota exceeded, falling back to server cache');
            // Silently continue - we'll fall back to server cache when needed
        }
    }

    function get_cache(options, last_resort) {
        var read_cache = last_resort || false;

        if (!last_resort && options.cache_time > 0) {
            var cached_time = localStorage.getItem(options.cache_time_key);
            if (cached_time !== null && parseInt(cached_time) + 1000 * 60 * options.cache_time > new Date().getTime()) {
                read_cache = true;
            }
        }

        if (read_cache) {
            try {
                // Check if data is chunked
                const chunks = localStorage.getItem(`${options.cache_data_key}_chunks`);
                if (chunks) {
                    // Reconstruct from chunks
                    let dataStr = '';
                    for (let i = 0; i < chunks; i++) {
                        const chunk = localStorage.getItem(`${options.cache_data_key}_chunk_${i}`);
                        if (!chunk) {
                            throw new Error('Incomplete chunked data');
                        }
                        dataStr += chunk;
                    }
                    return JSON.parse(dataStr);
                } else {
                    // Regular non-chunked data
                    const data = localStorage.getItem(options.cache_data_key);
                    if (data !== null) {
                        return JSON.parse(data);
                    }
                }
            } catch (e) {
                console.warn('Instagram Feed: Error reading from localStorage, falling back to server cache');
                // Clear potentially corrupted data
                clear_local_cache(options);
            }
        }
        return false;
    }

    function clear_local_cache(options) {
        try {
            // Clear any chunks
            for (let key in localStorage) {
                if (key.startsWith(options.cache_data_key)) {
                    localStorage.removeItem(key);
                }
            }
            localStorage.removeItem(options.cache_time_key);
        } catch (e) {
            // Ignore any errors during cleanup
        }
    }

    function set_image_cache(image_id, data){
        localStorage.setItem("wp_insta_img_" + image_id, JSON.stringify(data));
    }

    function get_image_cache(image_id){
        var data = localStorage.getItem("wp_insta_img_" + image_id);
        if(data !== null){
            return JSON.parse(data);
        }

        return false;
    }

    /**
     * Request / Response
     */
    function parse_response(response){
        try {
            let data = response.data;
            if(typeof data !== "undefined" && Array.isArray(data)){
                if (typeof response.paging !== "undefined" && typeof response.paging.next !== "undefined") {
                    nextPageUrl = response.paging.next;
                } else {
                    nextPageUrl = false;
                }
                return data;
            }
            return [];
        } catch (e) {
            console.warn('Instagram Feed: Error parsing response:', e);
            return [];
        }
    }

    function getNextPage(url, options) {
        $.get({
            url: options.instaServerFetchImageUrl + 'fetch/images',
            data: {
                instaFetchUrl: url
            },
            async: false,
            success: function(response){
                data = parse_response(response);
                if(data !== false){
                    nextPageData = data;
                    set_cache(options, data);
                    // return data;
                }else{
                    nextPageData = false;
                    // return false;
                }
            },
            error: function (e) {
                nextPageData = false;
                // return false;
            }
        });
    }

    function request_data(url, options, callback){
        $.get(
            options.instaServerFetchImageUrl + 'fetch/images',
            {
                instaFetchUrl: url + '?access_token=' + options.token + '&items=' + options.items + '&hashTagFilter=' + options.hashTagFilter +'&useHashTagFilter=' + options.useHashTagFilter + '&showVideos=' + options.show_videos
            },
            function(response){
                data = parse_response(response);
                if(data !== false && Array.isArray(data)){
                    callback(data);
                }else{
                    callback([]);
                }
            },
            'json')
            .fail(function (e) {
                console.warn('Instagram Feed: Request failed:', e);
                callback([], e);
        });
    }

    /**
     * Retrieve data
     */
    function get_data(options, callback){
        var data = get_cache(options, false);

        if(data !== false && Array.isArray(data)){
            callback(data);
        }else{
            var url = options.host;

            request_data(url, options, function(data, exception){
                if(data !== false && Array.isArray(data) && data.length > 0){
                    set_cache(options, data);
                    callback(data);
                }else{
                    data = get_cache(options, true);
                    if(data !== false && Array.isArray(data)){
                        callback(data);
                    }else{
                        callback([]);
                        if (exception) {
                            options.on_error("Instagram Feed: Unable to fetch: " + exception.status, 5);
                        } else {
                            options.on_error("Instagram Feed: No data available", 5);
                        }
                    }
                }
            });
        }
    }

    /**
     * Rendering
     */
    function render(options, data){
        // Ensure data is an array and has items
        if (!Array.isArray(data) || !data.length) {
            console.warn('Instagram Feed: No data available to display');
            $(options.container).html('');
            return;
        }

        var html = "";
        var videoOptions = 'playsinline controls loop muted';

        if (isMobile) videoOptions = 'playsinline autoplay loop muted';

        window.wpLazyLoad = window.wpLazyLoad || {};

        var hashTagFilterValue = '';
        if (options.useHashTagFilter) {
            hashTagFilterValue = '#' + options.hashTagFilter;
        }

        var max = (data.length > options.items) ? options.items : data.length;
        var i = 0, totalDisplays = 0;

        do {
            // Validate array index exists
            if (i >= data.length) {
                break;
            }

            // Validate required properties exist
            if (!data[i] || !data[i].media_type || !data[i].permalink || !data[i].media_url) {
                console.warn('Instagram Feed: Invalid data format for item', i);
                i++;
                continue;
            }

            var mediaType = data[i].media_type;
            var url = data[i].permalink;
            var image = data[i].media_url;
            var likeCount = (data[i].like_count) ? data[i].like_count : '';
            var caption = (data[i].caption) ? escape_string(data[i].caption) : '';

            // Skip invalid items
            if (!mediaType || !url || !image) {
                i++;
                continue;
            }

            if (mediaType.toUpperCase() == 'IMAGE' || mediaType.toUpperCase() == 'CAROUSEL_ALBUM') {
                if (!options.useHashTagFilter || (options.useHashTagFilter && caption.includes(hashTagFilterValue))) {
                    html += "    <a href='" + url + "'" + (options.show_like_count  ? " data-likecounts='" + likeCount + "'" : "") +  (options.display_captions && caption ? " data-caption='" + caption + "'" : "") + "  rel='noopener'" + options.image_new_tab + ">";
                    html += "   <span class='heart-icon'>" + (options.show_like_count  ?  likeCount : "") + "</span>"
                    if (options.image_lazy_load) {
                        html += "<span style='width: auto; height: 320px; float: none; display: block; position: relative;'>";
                        html += "       <img style='max-width: " + options.lazy_load_placeholder_width + " ;margin-left: 45%' src='" + window.wpLazyLoad.imageloader + "' class='lazy " + options.image_padding + "'" + " data-original='" + image + "' ";
                    } else {
                        html += "       <img class='" + options.image_padding + "'" + " src='" + image + "' ";
                    }
                    switch (options.image_alt_tag) {
                        case 1:
                            html += " alt='" + caption + "'";
                            break;
                        case 2:
                            html += " alt='" + options.image_alt_label + "'";
                            break;
                    }
                    html += " />";
                    if (options.image_lazy_load) {
                        html += "</span>";
                    }
                    html += "    </a>";
                    totalDisplays += 1;
                }
            } else if (options.show_videos && mediaType.toUpperCase() == 'VIDEO') {
                if (!options.useHashTagFilter || (options.useHashTagFilter && caption.includes(hashTagFilterValue))) {
                    html += "    <a href='" + url + "'" +  (options.show_like_count  ? " data-likecounts='" + likeCount + "'" : "") +  (options.display_captions && caption ? " data-caption='" + caption + "'" : "") + "  rel='noopener'" + options.image_new_tab + ">";
                    html += "   <span class='heart-icon'>" + (options.show_like_count  ?  likeCount : "") + "</span>"
                    html += "       <video " + videoOptions + " class='" + options.image_padding + "'><source src='" + image + "' ";
                    html += " type='video/mp4'>";
                    html += "    </a>";
                    totalDisplays += 1;
                }
            }
            i += 1;

            // Check for next page only if we need more items
            if (i == data.length && nextPageUrl && totalDisplays < max) {
                getNextPage(nextPageUrl, options);
                if (nextPageData && Array.isArray(nextPageData) && nextPageData.length) {
                    data = nextPageData;
                    i = 0;
                }
            }
        } while (totalDisplays < max && i < data.length);

        // Update container only if we have content
        if (html) {
            $(options.container).html(html);

            if (options.image_lazy_load) {
                $('img.lazy').lazyload({
                    effect: window.wpLazyLoad.effect || "fadeIn",
                    effectspeed: window.wpLazyLoad.effectspeed || "",
                    imageloader: window.wpLazyLoad.imageloader || "",
                    threshold: window.wpLazyLoad.threshold || "",
                    load: function () {
                        if ($(this).parents('.instagram-photos').length) {
                            $(this).parent().removeAttr("style");
                        }
                        $(this).css({'max-width':'100%'});
                        $(this).css({'margin-left':'0'});
                        setTimeout(function () {
                            $(window).scroll();
                        }, 500);
                    }
                });
            }

            if ((options.after != null) && typeof options.after === 'function') {
                var that = this;
                setTimeout(function(){
                    options.after.call(that);
                    $('.shuffle-item img.use-padding').css('width', '98%');
                    $('.shuffle-item video.use-padding').css('width', '98%');
                }, 1000);
            }
        } else {
            $(options.container).html('');
        }
    }

    $.instagramFeedApi = function (opts) {
        var options = $.fn.extend({}, defaults, opts);

        if (options.token == "") {
            options.on_error("Instagram Feed: Error, no token defined.", 1);
            return false;
        }

        options.cache_data_key = 'instagramFeedApi_' + options.container;
        options.cache_time_key = options.cache_data_key + '_time';

        get_data(options, function(data){
            if(options.container != ""){
                render(options, data);
            }
            if(options.callback != null){
                options.callback(data);
            }
        });
        return true;
    };

}));
