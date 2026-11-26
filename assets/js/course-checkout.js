(function () {
    const returnLink = document.getElementById('checkout-return');
    if (returnLink) {
        window.location.replace(returnLink.href);
        return;
    }

    const form = document.getElementById('checkout-link');
    if (!form) {
        return;
    }
    const token = window.location.hash.slice(1);
    window.history.replaceState(null, '', window.location.pathname);
    if (!/^(?:[0-9]+\.){4}[a-f0-9]{64}$/.test(token) || token.length > 110) {
        document.getElementById('checkout-link-status').textContent =
            'Откройте ссылку на оплату, которую вам отправил менеджер.';
        return;
    }

    form.elements.token.value = token;
    form.submit();
}());
