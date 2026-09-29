<script setup>
// Кольорові значки сервісів у рядках інтеграцій (18×18).
const ICONS = {
  trello: '<rect x="2" y="2" width="20" height="20" fill="#0079BF"></rect><rect x="4.5" y="4.5" width="6.5" height="10.5" fill="#ffffff"></rect><rect x="13" y="4.5" width="6.5" height="6.5" fill="#ffffff"></rect>',
  bitrix: '<rect x="2" y="2" width="20" height="20" fill="#1B65A6"></rect><circle cx="12" cy="12" r="6.2" fill="none" stroke="#ffffff" stroke-width="2.4"></circle><circle cx="12" cy="12" r="2" fill="#ffffff"></circle>',
  sheets: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#0F9D58"></path><path d="M14 2v6h6" fill="#8ED1A6"></path><rect x="7" y="12" width="10" height="1.6" fill="#ffffff"></rect><rect x="7" y="15.2" width="10" height="1.6" fill="#ffffff"></rect><rect x="7" y="18.4" width="6" height="1.6" fill="#ffffff"></rect>',
  telegram: '<circle cx="12" cy="12" r="10" fill="#229ED9"></circle><path d="M6.2 11.6l9.8-3.9c.5-.2.9.1.7.9l-1.6 7.8c-.1.6-.5.7-1 .4l-2.5-1.9-1.2 1.2c-.2.2-.4.3-.7.3l.2-2.6 4.8-4.4c.2-.2 0-.3-.3-.1l-6 3.8-2.5-.8c-.6-.2-.6-.6.3-.7z" fill="#ffffff"></path>',
};

defineProps({
  name: { type: String, required: true },
});
</script>

<template>
  <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" v-html="ICONS[name]"></svg>
</template>
