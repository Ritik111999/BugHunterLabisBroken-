@if ($pwaRegisterServiceWorker ?? true)
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register(@json(url('/sw.js'))).catch(function () {});
    });
}
</script>
@endif
