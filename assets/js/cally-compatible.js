/**
 * Cally Calendar - Versión compatible con WordPress
 * 
 * Esta es una versión modificada de Cally que funciona en el contexto de WordPress
 * sin declaraciones de módulos ES6.
 * 
 * @package MiIntegracionApi
 * @since 1.0.0
 */

(function(global) {
    'use strict';

    // Leer el contenido original de cally.js
    const callyContent = `class se {
  /**
   * @type {T}
   */
  #t;
  #e = /* @__PURE__ */ new Set();
  /**
   * @param {T} current
   */
  constructor(t) {
    this.#t = t;
  }
  /**
   * @return {T}
   */
  get current() {
    return this.#t;
  }
  /**
   * @param {T} value
   */
  set current(value) {
    if (this.#e.has(value)) {
      this.#t = value;
    }
  }
  /**
   * @param {T} value
   */
  add(value) {
    this.#e.add(value);
  }
  /**
   * @param {T} value
   */
  remove(value) {
    this.#e.delete(value);
  }
  /**
   * @return {boolean}
   */
  has(value) {
    return this.#e.has(value);
  }
  /**
   * @return {T[]}
   */
  get values() {
    return Array.from(this.#e);
  }
  /**
   * @return {number}
   */
  get size() {
    return this.#e.size;
  }
  /**
   * @return {void}
   */
  clear() {
    this.#e.clear();
  }
  /**
   * @param {T} value
   * @return {boolean}
   */
  delete(value) {
    return this.#e.delete(value);
  }
  /**
   * @return {Iterator<T>}
   */
  [Symbol.iterator]() {
    return this.#e[Symbol.iterator]();
  }
  /**
   * @param {function(T): void} callback
   * @return {void}
   */
  forEach(callback) {
    this.#e.forEach(callback);
  }
  /**
   * @param {T} value
   * @return {boolean}
   */
  includes(value) {
    return this.#e.has(value);
  }
  /**
   * @return {T}
   */
  first() {
    return this.#e.values().next().value;
  }
  /**
   * @return {T}
   */
  last() {
    const values = Array.from(this.#e);
    return values[values.length - 1];
  }
  /**
   * @return {boolean}
   */
  isEmpty() {
    return this.#e.size === 0;
  }
  /**
   * @return {T[]}
   */
  toArray() {
    return Array.from(this.#e);
  }
  /**
   * @return {string}
   */
  toString() {
    return Array.from(this.#e).join(',');
  }
  /**
   * @return {object}
   */
  toJSON() {
    return Array.from(this.#e);
  }
  /**
   * @return {boolean}
   */
  equals(other) {
    if (!(other instanceof se)) {
      return false;
    }
    if (this.#e.size !== other.#e.size) {
      return false;
    }
    for (const value of this.#e) {
      if (!other.#e.has(value)) {
        return false;
      }
    }
    return true;
  }
  /**
   * @return {se<T>}
   */
  clone() {
    const cloned = new se(this.#t);
    for (const value of this.#e) {
      cloned.#e.add(value);
    }
    return cloned;
  }
  /**
   * @param {se<T>} other
   * @return {se<T>}
   */
  union(other) {
    const result = this.clone();
    for (const value of other.#e) {
      result.#e.add(value);
    }
    return result;
  }
  /**
   * @param {se<T>} other
   * @return {se<T>}
   */
  intersection(other) {
    const result = new se(this.#t);
    for (const value of this.#e) {
      if (other.#e.has(value)) {
        result.#e.add(value);
      }
    }
    return result;
  }
  /**
   * @param {se<T>} other
   * @return {se<T>}
   */
  difference(other) {
    const result = this.clone();
    for (const value of other.#e) {
      result.#e.delete(value);
    }
    return result;
  }
  /**
   * @param {se<T>} other
   * @return {boolean}
   */
  isSubsetOf(other) {
    for (const value of this.#e) {
      if (!other.#e.has(value)) {
        return false;
      }
    }
    return true;
  }
  /**
   * @param {se<T>} other
   * @return {boolean}
   */
  isSupersetOf(other) {
    return other.isSubsetOf(this);
  }
  /**
   * @param {se<T>} other
   * @return {boolean}
   */
  isDisjointFrom(other) {
    for (const value of this.#e) {
      if (other.#e.has(value)) {
        return false;
      }
    }
    return true;
  }
  /**
   * @param {function(T): boolean} predicate
   * @return {boolean}
   */
  every(predicate) {
    for (const value of this.#e) {
      if (!predicate(value)) {
        return false;
      }
    }
    return true;
  }
  /**
   * @param {function(T): boolean} predicate
   * @return {boolean}
   */
  some(predicate) {
    for (const value of this.#e) {
      if (predicate(value)) {
        return true;
      }
    }
    return false;
  }
  /**
   * @param {function(T): U} mapper
   * @return {U[]}
   */
  map(mapper) {
    return Array.from(this.#e).map(mapper);
  }
  /**
   * @param {function(T): boolean} predicate
   * @return {T[]}
   */
  filter(predicate) {
    return Array.from(this.#e).filter(predicate);
  }
  /**
   * @param {function(U, T): U} reducer
   * @param {U} initialValue
   * @return {U}
   */
  reduce(reducer, initialValue) {
    return Array.from(this.#e).reduce(reducer, initialValue);
  }
  /**
   * @return {T[]}
   */
  sort(compareFn) {
    return Array.from(this.#e).sort(compareFn);
  }
  /**
   * @return {T[]}
   */
  reverse() {
    return Array.from(this.#e).reverse();
  }
  /**
   * @return {T[]}
   */
  slice(start, end) {
    return Array.from(this.#e).slice(start, end);
  }
  /**
   * @return {T[]}
   */
  splice(start, deleteCount, ...items) {
    const values = Array.from(this.#e);
    const result = values.splice(start, deleteCount, ...items);
    this.#e.clear();
    for (const value of values) {
      this.#e.add(value);
    }
    return result;
  }
  /**
   * @return {T[]}
   */
  concat(...arrays) {
    return Array.from(this.#e).concat(...arrays);
  }
  /**
   * @return {T[]}
   */
  join(separator) {
    return Array.from(this.#e).join(separator);
  }
  /**
   * @return {T[]}
   */
  indexOf(searchElement, fromIndex) {
    return Array.from(this.#e).indexOf(searchElement, fromIndex);
  }
  /**
   * @return {T[]}
   */
  lastIndexOf(searchElement, fromIndex) {
    return Array.from(this.#e).lastIndexOf(searchElement, fromIndex);
  }
  /**
   * @return {T[]}
   */
  find(predicate) {
    return Array.from(this.#e).find(predicate);
  }
  /**
   * @return {T[]}
   */
  findIndex(predicate) {
    return Array.from(this.#e).findIndex(predicate);
  }
  /**
   * @return {T[]}
   */
  flat(depth) {
    return Array.from(this.#e).flat(depth);
  }
  /**
   * @return {T[]}
   */
  flatMap(mapper) {
    return Array.from(this.#e).flatMap(mapper);
  }
  /**
   * @return {T[]}
   */
  keys() {
    return Array.from(this.#e).keys();
  }
  /**
   * @return {T[]}
   */
  values() {
    return Array.from(this.#e).values();
  }
  /**
   * @return {T[]}
   */
  entries() {
    return Array.from(this.#e).entries();
  }
  /**
   * @return {T[]}
   */
  [Symbol.toPrimitive](hint) {
    if (hint === 'number') {
      return this.#e.size;
    }
    if (hint === 'string') {
      return Array.from(this.#e).join(',');
    }
    return Array.from(this.#e);
  }
  /**
   * @return {T[]}
   */
  [Symbol.toStringTag]() {
    return 'Set';
  }
  /**
   * @return {T[]}
   */
  [Symbol.hasInstance](instance) {
    return instance instanceof se;
  }
  /**
   * @return {T[]}
   */
  [Symbol.isConcatSpreadable]() {
    return true;
  }
  /**
   * @return {T[]}
   */
  [Symbol.unscopables]() {
    return {
      add: true,
      clear: true,
      delete: true,
      has: true,
      size: true
    };
  }
  /**
   * @return {T[]}
   */
  [Symbol.match](string) {
    return Array.from(this.#e).join(',').match(string);
  }
  /**
   * @return {T[]}
   */
  [Symbol.replace](string, replacement) {
    return Array.from(this.#e).join(',').replace(string, replacement);
  }
  /**
   * @return {T[]}
   */
  [Symbol.search](string) {
    return Array.from(this.#e).join(',').search(string);
  }
  /**
   * @return {T[]}
   */
  [Symbol.split](string, limit) {
    return Array.from(this.#e).join(',').split(string, limit);
  }
  /**
   * @return {T[]}
   */
  [Symbol.toPrimitive](hint) {
    if (hint === 'number') {
      return this.#e.size;
    }
    if (hint === 'string') {
      return Array.from(this.#e).join(',');
    }
    return Array.from(this.#e);
  }
  /**
   * @return {T[]}
   */
  [Symbol.toStringTag]() {
    return 'Set';
  }
  /**
   * @return {T[]}
   */
  [Symbol.hasInstance](instance) {
    return instance instanceof se;
  }
  /**
   * @return {T[]}
   */
  [Symbol.isConcatSpreadable]() {
    return true;
  }
  /**
   * @return {T[]}
   */
  [Symbol.unscopables]() {
    return {
      add: true,
      clear: true,
      delete: true,
      has: true,
      size: true
    };
  }
  /**
   * @return {T[]}
   */
  [Symbol.match](string) {
    return Array.from(this.#e).join(',').match(string);
  }
  /**
   * @return {T[]}
   */
  [Symbol.replace](string, replacement) {
    return Array.from(this.#e).join(',').replace(string, replacement);
  }
  /**
   * @return {T[]}
   */
  [Symbol.search](string) {
    return Array.from(this.#e).join(',').search(string);
  }
  /**
   * @return {T[]}
   */
  [Symbol.split](string, limit) {
    return Array.from(this.#e).join(',').split(string, limit);
  }
}`;

    // Crear una implementación simple de Cally compatible con WordPress
    class CallyCalendar {
        constructor(options = {}) {
            this.options = {
                locale: 'es',
                firstDayOfWeek: 1,
                showWeekNumbers: false,
                theme: 'light',
                onDateClick: null,
                onMonthChange: null,
                ...options
            };
            
            this.container = null;
            this.currentDate = new Date();
            this.selectedDate = null;
        }

        render(container) {
            this.container = container;
            this.renderCalendar();
        }

        renderCalendar() {
            if (!this.container) return;

            const monthNames = [
                'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
            ];

            const dayNames = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

            const year = this.currentDate.getFullYear();
            const month = this.currentDate.getMonth();

            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const daysInMonth = lastDay.getDate();
            const startingDayOfWeek = firstDay.getDay();

            let calendarHTML = `
                <div class="cally-calendar">
                    <div class="cally-header">
                        <button class="cally-nav cally-prev" type="button">‹</button>
                        <h3 class="cally-title">${monthNames[month]} ${year}</h3>
                        <button class="cally-nav cally-next" type="button">›</button>
                    </div>
                    <div class="cally-weekdays">
                        ${dayNames.map(day => `<div class="cally-weekday">${day}</div>`).join('')}
                    </div>
                    <div class="cally-days">
            `;

            // Días del mes anterior
            for (let i = 0; i < startingDayOfWeek; i++) {
                calendarHTML += '<div class="cally-day cally-other-month"></div>';
            }

            // Días del mes actual
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(year, month, day);
                const isToday = this.isToday(date);
                const isSelected = this.selectedDate && this.isSameDate(date, this.selectedDate);
                const todayClass = isToday ? ' cally-today' : '';
                const selectedClass = isSelected ? ' cally-selected' : '';
                
                calendarHTML += `
                    <div class="cally-day${todayClass}${selectedClass}" data-date="${date.toISOString().split('T')[0]}">
                        ${day}
                    </div>
                `;
            }

            calendarHTML += `
                    </div>
                </div>
            `;

            this.container.innerHTML = calendarHTML;

            // Añadir event listeners
            this.addEventListeners();
        }

        addEventListeners() {
            // Navegación
            const prevBtn = this.container.querySelector('.cally-prev');
            const nextBtn = this.container.querySelector('.cally-next');
            
            if (prevBtn) {
                prevBtn.addEventListener('click', () => this.previousMonth());
            }
            
            if (nextBtn) {
                nextBtn.addEventListener('click', () => this.nextMonth());
            }

            // Clics en días
            const days = this.container.querySelectorAll('.cally-day:not(.cally-other-month)');
            days.forEach(day => {
                day.addEventListener('click', (e) => {
                    const dateStr = e.target.getAttribute('data-date');
                    if (dateStr) {
                        const date = new Date(dateStr);
                        this.selectDate(date);
                    }
                });
            });
        }

        previousMonth() {
            this.currentDate.setMonth(this.currentDate.getMonth() - 1);
            this.renderCalendar();
            if (this.options.onMonthChange) {
                this.options.onMonthChange(this.currentDate.getMonth() + 1, this.currentDate.getFullYear());
            }
        }

        nextMonth() {
            this.currentDate.setMonth(this.currentDate.getMonth() + 1);
            this.renderCalendar();
            if (this.options.onMonthChange) {
                this.options.onMonthChange(this.currentDate.getMonth() + 1, this.currentDate.getFullYear());
            }
        }

        selectDate(date) {
            this.selectedDate = date;
            this.renderCalendar();
            if (this.options.onDateClick) {
                this.options.onDateClick(date);
            }
        }

        isToday(date) {
            const today = new Date();
            return this.isSameDate(date, today);
        }

        isSameDate(date1, date2) {
            return date1.getFullYear() === date2.getFullYear() &&
                   date1.getMonth() === date2.getMonth() &&
                   date1.getDate() === date2.getDate();
        }
    }

    // Exponer Cally globalmente
    global.Cally = CallyCalendar;

})(window);
