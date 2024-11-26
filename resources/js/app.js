import './bootstrap';
import { createApp } from 'vue';
import App from './components/App.vue';
import 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';

const app = createApp({});
app.component('app-main', App);
app.mount('#app');