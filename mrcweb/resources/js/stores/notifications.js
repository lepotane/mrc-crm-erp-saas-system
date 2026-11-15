import { defineStore } from 'pinia';
import client from '../api/client';

export const useNotificationStore = defineStore('notifications', {
    state: () => ({
        items: [],
        unreadCount: 0,
        loading: false,
        popups: [],
        seenIds: new Set(),
        initialized: false,
        pollTimer: null,
    }),

    actions: {
        async fetchNotifications({ triggerPopups = true } = {}) {
            try {
                const { data } = await client.get('/notifications');
                const list = Array.isArray(data?.notifications) ? data.notifications : [];
                this.unreadCount = Number(data?.unread_count ?? list.filter((n) => !n.read).length);

                if (triggerPopups) {
                    const unreadItems = list.filter((n) => !n.read);
                    if (!this.initialized) {
                        // İlk açılışta en güncel okunmamış bildirimi sağ üstten popup olarak göster
                        if (unreadItems.length > 0) {
                            this.enqueuePopup(unreadItems[0]);
                        }
                        list.forEach((n) => this.seenIds.add(n.id));
                        this.initialized = true;
                    } else {
                        // Sonraki yoklamalarda yeni gelen okunmamış bildirimleri popup olarak göster
                        unreadItems.forEach((n) => {
                            if (!this.seenIds.has(n.id)) {
                                this.seenIds.add(n.id);
                                this.enqueuePopup(n);
                            }
                        });
                        list.forEach((n) => this.seenIds.add(n.id));
                    }
                }

                this.items = list;
            } catch {
                // Oturum kapalıysa veya ağ geçici meşgulse sessiz geç
            }
        },

        enqueuePopup(notification) {
            if (!notification || !notification.id) return;
            const popupId = `${notification.id}-${Date.now()}`;
            if (this.popups.some((p) => p.id === notification.id)) return;

            const item = {
                ...notification,
                popupId,
            };
            this.popups.unshift(item);
            if (this.popups.length > 3) {
                this.popups = this.popups.slice(0, 3);
            }

            setTimeout(() => {
                this.dismissPopup(popupId);
            }, 5500);
        },

        dismissPopup(popupId) {
            this.popups = this.popups.filter((p) => p.popupId !== popupId);
        },

        async markRead(id) {
            const target = this.items.find((n) => n.id === id);
            if (target && !target.read) {
                target.read = true;
                target.read_at = new Date().toISOString();
                this.unreadCount = Math.max(0, this.unreadCount - 1);
            }
            try {
                const { data } = await client.post(`/notifications/${id}/read`);
                if (typeof data?.unread_count === 'number') {
                    this.unreadCount = data.unread_count;
                }
            } catch {
                // ignore
            }
        },

        async markAllRead() {
            this.items.forEach((n) => {
                n.read = true;
                n.read_at = n.read_at || new Date().toISOString();
            });
            this.unreadCount = 0;
            try {
                await client.post('/notifications/read-all');
            } catch {
                // ignore
            }
        },

        async removeNotification(id) {
            const target = this.items.find((n) => n.id === id);
            if (target && !target.read) {
                this.unreadCount = Math.max(0, this.unreadCount - 1);
            }
            this.items = this.items.filter((n) => n.id !== id);
            try {
                const { data } = await client.delete(`/notifications/${id}`);
                if (typeof data?.unread_count === 'number') {
                    this.unreadCount = data.unread_count;
                }
            } catch {
                // ignore
            }
        },

        async clearAll() {
            this.items = [];
            this.unreadCount = 0;
            this.popups = [];
            try {
                await client.delete('/notifications/clear-all');
            } catch {
                // ignore
            }
        },

        startPolling() {
            this.stopPolling();
            this.fetchNotifications({ triggerPopups: true });
            this.pollTimer = setInterval(() => {
                this.fetchNotifications({ triggerPopups: true });
            }, 22000);
        },

        stopPolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },
    },
});
