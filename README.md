# RTL Fridge Church Daemon

The name of the repository is self explanatory... or maybe not...

This is mainly a personal project, for my own need. I decided to publish it here on GitHub anyway, in case anyone was curious, or wanted to take some inspiration.

## The story behind the project

The reason of this project is that I needed a way to monitor the temperature of my freezer so that I would be aware of any problems (malfunctions, power failure, door not closed properly).

I found [this](https://www.amazon.it/gp/product/B0894NVTQF) pair of sensors on Amazon, that would have been a good enough solution: they are wireless, with a good range, and it is possible to set sound alarms on the receiver if the temperature goes outside a set range.

However, after a bit of research, I discovered that most home wireless temperature sensors transmit at a frequency of 433 MHz, using protocols that are fairly easy to decode. In addition, I found [rtl_433](https://github.com/merbanan/rtl_433), a ready-to-use software that allows you to receive data from various wireless sensors.

I therefore decided to buy a receiver (to be precise, I bought a [RTL-SDR dongle](https://www.rtl-sdr.com/buy-rtl-sdr-dvb-t-dongles/), that allow you to receive radio signals in the range 500 kHz - 1766 MHz) and do some testing to integrate it with my Home Assistant instance.

Thanks also to [zuckschwerdt](https://github.com/zuckschwerdt)'s help in solving a [problem](https://github.com/merbanan/rtl_433/issues/2088), I easily managed to achieve my goal!

Meanwhile, playing a bit with the RTL-SDR receiver, I was thinking of some other interesting uses for it, and I remembered that the parish in my town broadcasts church services and events over the air, but you can only listen to them via a special radio they sell.

I then tried to receive the signal with my RTL dongle, and discovered that the parish broadcasts in the [862-876 MHz range](https://www.radio-scanner.it/862-876-mhz.html), and it is therefore possible to listen to it via a RTL-SDR radio software, like [gqrx](https://github.com/gqrx-sdr/gqrx) just as easily as a normal FM radio.

The challenge now was to find a way to receive both freezer temperatures and parish radio, using the same RTL-SDR dongle, as the receiver can only receive one frequency at a time (to be precise, a small range of frequencies).

It would have been a waste to buy another one, considering that the temperature sensors transmit about once a minute, and the parish radio transmits for a couple of hours a day at most.

Hence the idea to create this tool, which alternates between the two frequencies (433 MHz and 865 MHz) in an intelligent manner.

In particular, the tool starts receiving on the 433 MHz frequency, then when it receives the temperatures of both freezer sensors it switch on the 865 Mhz frequency and check if a broadcast is being made on the parish radio.

If so, it stays tuned to that frequency, it starts recording the audio and transmit it to my [Icecast](https://icecast.org/) server, so I can then simply listen to it using [VLC](https://www.videolan.org/) or [CustomRadioPlayer](https://play.google.com/store/apps/details?id=de.battlestr1k3.radionerd) from any device connected to the Internet.

If there is no transmission instead, it returns to the 433 MHz frequency in order to receive the next temperature update, and then check the 865 MHz frequency again.

This means that while there is a broadcast on the parish radio, the freezer temperatures are not updated in Home Assistant, but this is not a big problem for me, as parish radio broadcasts do not usually take up more than a couple of hours a day. Obviously, this project would not be usable for a traditional radio, broadcast 24 hours a day.

The project is at a good stage of development, but is not yet completed. One important feature that is missing is the one that checks whether the radio broadcast is still going on, and stops streaming when the broadcast is finished, so that it can return to the 433 MHz frequency to receive the freezer temperatures.
