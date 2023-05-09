(
  function ($, Drupal, drupalSettings) {
    'use strict'

    Drupal.behaviors.greenMoneyExchange = {
      attach: function (context, settings) {
        if (context !== document) {
          return;
        }

        // Chart title
        const title = settings.green_money_exchange.title;
        // An associative array of exchange data for each currency
        const currencyData = filterCerrency(settings.green_money_exchange.currencyData);
        // Array date range
        const range = getDateRange(currencyData);

        const chartData = getChartData(currencyData);

        // Get list of currency return associative array where currency name is key
        function filterCerrency(currency) {
          let filtredCurrencyList = {};

          Object.entries(currency).forEach(([key, value]) => {

            if (filtredCurrencyList[value.cc]) {
              filtredCurrencyList[value.cc].push(value);
            } else {
              filtredCurrencyList[value.cc] = [value];
            }
          });

          return filtredCurrencyList;
        }

        // Returns an array with dates for which a graph of currency rates is constructed
        function getDateRange(obj) {
          let firstArr = Object.keys(obj)[0];
          let dateArr = [];
          let rangeDates = Object.entries(obj[firstArr]).forEach(([key, value]) => {
            dateArr.push(value.exchangedate);
          });

          return dateArr.reverse();
        }

        // Return random color
        function getRandomColor() {

          let r = Math.floor(Math.random() * 255);
          let g = Math.floor(Math.random() * 255);
          let b = Math.floor(Math.random() * 255);

          return `rgb(${r}, ${g}, ${b})`;
        }

        // Returns an array of the given currencies to display in the chart
        function getChartData(obj) {

          if (!obj) {
            return [];
          }

          let returnData = [];
          Object.entries(obj).forEach(([key, value]) => {
            let item = {
              'label': '',
              data: [],
              'lineTension': 0,
              'fill': false,
              borderColor: getRandomColor()
            };
            item['label'] = key;
            value.reverse().forEach(el => {
              item.data.push(el.rate)
            });

            returnData.push(item);

          });

          return returnData;
        }


        const ctx = document.getElementById('greenChart');

        new Chart(ctx, {
          type: 'line',
          data: {
            labels: range,
            datasets: chartData
          },
          options: {
            scales: {
              y: {
                beginAtZero: true
              }
            }
          }
        });
      }
    };
  }
)(jQuery, Drupal, drupalSettings);

